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
 * Powiadomienie wysyłane przez P24 na adres urlStatus.
 *
 * Powiadomienie samo w sobie niczego nie potwierdza. Jest wyłącznie
 * sygnałem, że warto zapytać P24 o stan transakcji przez transaction/verify.
 * Dlatego ta klasa tylko odczytuje dane i sprawdza, czy żądanie w ogóle
 * pochodzi od P24 i dotyczy tego zamówienia.
 */
final class Notification
{
    public function __construct(
        public readonly int $merchantId,
        public readonly int $posId,
        public readonly string $sessionId,
        public readonly int $amount,
        public readonly int $originAmount,
        public readonly string $currency,
        public readonly int $p24OrderId,
        public readonly int $methodId,
        public readonly ?string $statement,
        public readonly string $sign
    ) {
    }

    /**
     * Odczytuje powiadomienie z treści żądania.
     *
     * Każde pole czytamy zachowawczo: niekompletne żądanie ma zostać
     * odrzucone na sprawdzeniu podpisu, a nie wysypać się wcześniej
     * na braku klucza w tablicy.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            merchantId: (int) ($data['merchantId'] ?? 0),
            posId: (int) ($data['posId'] ?? 0),
            sessionId: isset($data['sessionId']) ? (string) $data['sessionId'] : '',
            amount: (int) ($data['amount'] ?? 0),
            originAmount: (int) ($data['originAmount'] ?? 0),
            currency: isset($data['currency']) ? (string) $data['currency'] : '',
            p24OrderId: (int) ($data['orderId'] ?? 0),
            methodId: (int) ($data['methodId'] ?? 0),
            statement: isset($data['statement']) ? (string) $data['statement'] : null,
            sign: isset($data['sign']) ? (string) $data['sign'] : ''
        );
    }

    /**
     * Odczytuje powiadomienie z surowej treści żądania.
     *
     * @throws SignatureException  gdy treść nie jest obiektem JSON
     */
    public static function fromRequestBody(string $body): self
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new SignatureException('Treść powiadomienia nie jest poprawnym JSON-em', 0, $exception);
        }

        if (!\is_array($decoded)) {
            throw new SignatureException('Treść powiadomienia nie jest obiektem JSON');
        }

        return self::fromArray($decoded);
    }

    /**
     * Sprawdza, czy powiadomienie pochodzi od P24 i dotyczy tej transakcji.
     *
     * Podpis liczymy z identyfikatora sesji zapisanego przy zamówieniu,
     * nie z tego, który przyszedł w żądaniu. Dzięki temu podstawiony
     * sessionId nie przejdzie, nawet gdyby ktoś znał resztę pól.
     *
     * @param  string  $storedSessionId  identyfikator zapisany przy zamówieniu
     * @param  int     $expectedAmount   kwota zamówienia w groszach
     * @param  string  $expectedCurrency waluta zamówienia
     *
     * @throws SignatureException
     */
    public function assertValid(
        Config $config,
        string $storedSessionId,
        int $expectedAmount,
        string $expectedCurrency
    ): void {
        if ($storedSessionId === '' || !hash_equals($storedSessionId, $this->sessionId)) {
            throw new SignatureException('Powiadomienie dotyczy innej sesji płatności niż zapisana przy zamówieniu');
        }

        if ($this->merchantId !== $config->merchantId) {
            throw new SignatureException('Powiadomienie wskazuje innego sprzedawcę niż skonfigurowany');
        }

        $expectedSign = Signature::forNotification(
            $config->merchantId,
            $config->posId,
            $storedSessionId,
            $this->amount,
            $this->originAmount,
            $this->currency,
            $this->p24OrderId,
            $this->methodId,
            $this->statement,
            $config->crc
        );

        if (!Signature::matches($expectedSign, $this->sign)) {
            throw new SignatureException('Podpis powiadomienia nie zgadza się z wyliczonym');
        }

        // Kwota i waluta decydują o tym, czy klient zapłacił tyle, ile miał.
        // Sprawdzamy je tutaj, żeby żadna ścieżka nie mogła tego pominąć.
        if (!Amount::equals($expectedAmount, $this->amount)) {
            throw new SignatureException(
                'Kwota w powiadomieniu (' . $this->amount . ' gr) różni się od kwoty zamówienia (' . $expectedAmount . ' gr)'
            );
        }

        if (strcasecmp($expectedCurrency, $this->currency) !== 0) {
            throw new SignatureException(
                'Waluta w powiadomieniu (' . $this->currency . ') różni się od waluty zamówienia (' . $expectedCurrency . ')'
            );
        }
    }

    /**
     * Dane do logu. Bez podpisu, bo ten bywa mylony z sekretem.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'sessionId'   => $this->sessionId,
            'p24_order'   => $this->p24OrderId,
            'kwota_gr'    => $this->amount,
            'waluta'      => $this->currency,
            'metoda_p24'  => $this->methodId,
        ];
    }
}
