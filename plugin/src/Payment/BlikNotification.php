<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\SignatureException;

\defined('_JEXEC') or die;

/**
 * Dodatkowe powiadomienie BLIK, wysyłane przez P24 na adres
 * urlCardPaymentNotification.
 *
 * Mówi, jak skończyła się autoryzacja w banku. To jedyne miejsce, z którego
 * sklep dowiaduje się, DLACZEGO płatność kodem BLIK się nie udała: po
 * przyjęciu kodu chargeByCode odpowiada 201, a odrzucona transakcja wygląda
 * w transaction/by/sessionId tak samo jak trwająca (sandbox, 09.10.2026).
 *
 * Zapłaty to powiadomienie nie potwierdza. Od tego jest zwykłe powiadomienie
 * na urlStatus i transaction/verify.
 */
final class BlikNotification
{
    /** Kolejność kluczy pola result podana w dokumentacji P24. */
    private const RESULT_ORDER = ['error', 'message', 'status', 'trxRef', 'alternativeKeys'];

    /**
     * @param  array<string, mixed>  $result  pole result dokładnie tak, jak przyszło
     */
    public function __construct(
        public readonly int $p24OrderId,
        public readonly string $sessionId,
        public readonly int $methodId,
        public readonly array $result,
        public readonly string $sign
    ) {
    }

    /**
     * Odczytuje powiadomienie z surowej treści żądania.
     *
     * Dokumentacja pokazuje pola w obiekcie data, a oficjalna wtyczka P24
     * dla WooCommerce przyjmuje je także wprost w korzeniu. Przyjmujemy oba
     * kształty.
     *
     * @throws SignatureException  gdy treść nie jest kompletnym powiadomieniem
     */
    public static function fromRequestBody(string $body): self
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SignatureException('Treść powiadomienia BLIK nie jest poprawnym JSON-em', 0, $exception);
        }

        if (\is_array($data) && isset($data['data']) && \is_array($data['data'])) {
            $data = $data['data'];
        }

        if (!\is_array($data) || !isset($data['result']) || !\is_array($data['result'])) {
            throw new SignatureException('Powiadomienie BLIK nie ma pola result');
        }

        $powiadomienie = new self(
            p24OrderId: (int) ($data['orderId'] ?? 0),
            sessionId: \is_scalar($data['sessionId'] ?? null) ? (string) $data['sessionId'] : '',
            methodId: (int) ($data['method'] ?? 0),
            result: $data['result'],
            sign: \is_scalar($data['sign'] ?? null) ? (string) $data['sign'] : ''
        );

        if ($powiadomienie->p24OrderId <= 0
            || $powiadomienie->sessionId === ''
            || $powiadomienie->errorCode() === ''
            || $powiadomienie->sign === ''
        ) {
            throw new SignatureException('Powiadomienie BLIK jest niekompletne');
        }

        return $powiadomienie;
    }

    /**
     * Kod błędu z banku. „0” oznacza powodzenie.
     */
    public function errorCode(): string
    {
        return $this->text('error');
    }

    public function message(): string
    {
        return $this->text('message');
    }

    public function status(): string
    {
        return $this->text('status');
    }

    public function hasError(): bool
    {
        return $this->errorCode() !== '0';
    }

    /**
     * Przyczyna odrzucenia w kategoriach, które rozumie reszta wtyczki.
     */
    public function reason(): BlikError
    {
        return BlikError::fromNotification($this->errorCode(), $this->message());
    }

    /**
     * Sprawdza, czy powiadomienie pochodzi od P24 i dotyczy tego zamówienia.
     *
     * @param  list<string>  $orderSessions  sesje płatności zapisane przy zamówieniu
     *
     * @throws SignatureException
     */
    public function assertValid(Config $config, array $orderSessions): void
    {
        if (!\in_array($this->sessionId, $orderSessions, true)) {
            throw new SignatureException('Powiadomienie BLIK dotyczy sesji płatności, której nie ma przy zamówieniu');
        }

        // Podpis obejmuje pole result razem z kolejnością jego kluczy.
        // Najpierw kolejność z dokumentacji, potem ta, w której pole
        // przyszło: P24 nie mówi, której używa, a obie są równie trudne
        // do podrobienia bez klucza CRC.
        foreach ([$this->resultInDocumentedOrder(), $this->result] as $result) {
            $oczekiwany = Signature::forBlikNotification(
                $this->p24OrderId,
                $this->sessionId,
                $this->methodId,
                $result,
                $config->crc
            );

            if (Signature::matches($oczekiwany, $this->sign)) {
                return;
            }
        }

        throw new SignatureException('Podpis powiadomienia BLIK nie zgadza się z wyliczonym');
    }

    /**
     * Dane do logu. Bez podpisu, bo ten bywa mylony z sekretem.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'sessionId'  => $this->sessionId,
            'p24_order'  => $this->p24OrderId,
            'metoda_p24' => $this->methodId,
            'blad'       => $this->errorCode(),
            'komunikat'  => $this->message(),
            'status'     => $this->status(),
        ];
    }

    /**
     * Kształt powiadomienia bez wartości podpisu, do dziennika.
     *
     * Formatu tego powiadomienia nie dało się obejrzeć przed wydaniem, więc
     * gdy podpis się nie zgadza, dziennik musi pokazać, co naprawdę przyszło.
     */
    public static function describeBody(string $body): string
    {
        $bezPodpisu = preg_replace('/("sign"\s*:\s*")[^"]*(")/', '$1(pominięty)$2', $body) ?? '';

        return mb_substr($bezPodpisu, 0, 800);
    }

    /**
     * @return array<string, mixed>
     */
    private function resultInDocumentedOrder(): array
    {
        $uporzadkowane = [];

        foreach (self::RESULT_ORDER as $klucz) {
            if (\array_key_exists($klucz, $this->result)) {
                $uporzadkowane[$klucz] = $this->result[$klucz];
            }
        }

        return $uporzadkowane + $this->result;
    }

    private function text(string $klucz): string
    {
        $wartosc = $this->result[$klucz] ?? '';

        return \is_scalar($wartosc) ? trim((string) $wartosc) : '';
    }
}
