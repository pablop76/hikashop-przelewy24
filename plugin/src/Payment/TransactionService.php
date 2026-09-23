<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;

\defined('_JEXEC') or die;

/**
 * Operacje na transakcji P24.
 *
 * Kolejność jest niezmienna: rejestracja daje token, token prowadzi
 * klienta na stronę płatności, powiadomienie każe sprawdzić transakcję,
 * a dopiero poprawna weryfikacja pozwala uznać zapłatę.
 */
final class TransactionService
{
    /** Odpowiedź weryfikacji, która oznacza zapłatę. */
    private const VERIFY_STATUS_SUCCESS = 'success';

    public function __construct(
        private readonly ApiClient $client,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Rejestruje transakcję i zwraca token strony płatności.
     *
     * @throws ApiException  gdy P24 nie zwróci tokenu
     */
    public function register(RegisterRequest $request): string
    {
        $response = $this->client->post(
            Endpoints::TRANSACTION_REGISTER,
            $request->toPayload($this->config)
        );

        $token = $response->get('token');

        if (!\is_string($token) || $token === '') {
            $this->logger->error('P24 nie zwróciło tokenu transakcji', [
                'sessionId' => $request->sessionId,
                'opis'      => $response->describe(),
            ]);

            throw new ApiException('P24 nie zwróciło tokenu transakcji', $response->httpStatus);
        }

        $this->logger->info('Transakcja zarejestrowana', [
            'sessionId' => $request->sessionId,
            'kwota_gr'  => $request->amountInMinorUnits,
            'waluta'    => $request->currency,
        ]);

        return $token;
    }

    /**
     * Potwierdza transakcję w P24.
     *
     * To jedyny moment, w którym wolno uznać płatność za dokonaną.
     * Kwotę i walutę bierzemy z zamówienia, nie z powiadomienia, żeby
     * podstawiona kwota nie mogła przejść weryfikacji.
     *
     * @param  int  $amountInMinorUnits  kwota zamówienia w groszach
     *
     * @throws ApiException
     */
    public function verify(
        string $sessionId,
        int $p24OrderId,
        int $amountInMinorUnits,
        string $currency
    ): bool {
        $currency = strtoupper($currency);

        $payload = [
            'merchantId' => $this->config->merchantId,
            'posId'      => $this->config->posId,
            'sessionId'  => $sessionId,
            'amount'     => $amountInMinorUnits,
            'currency'   => $currency,
            'orderId'    => $p24OrderId,
            'sign'       => Signature::forVerify(
                $sessionId,
                $p24OrderId,
                $amountInMinorUnits,
                $currency,
                $this->config->crc
            ),
            'encoding'   => 'UTF-8',
        ];

        $response = $this->client->put(Endpoints::TRANSACTION_VERIFY, $payload);

        $status = $response->get('status');
        $verified = \is_string($status) && strtolower($status) === self::VERIFY_STATUS_SUCCESS;

        $this->logger->info($verified ? 'Transakcja zweryfikowana' : 'Weryfikacja nie potwierdziła zapłaty', [
            'sessionId' => $sessionId,
            'p24_order' => $p24OrderId,
            'status'    => \is_scalar($status) ? (string) $status : '?',
        ]);

        return $verified;
    }

    /**
     * Pyta P24 o stan transakcji po identyfikatorze sesji.
     *
     * Przydaje się, gdy klient wraca do sklepu, a powiadomienie jeszcze
     * nie dotarło: zamiast zgadywać ze strony powrotu, pytamy wprost.
     *
     * @return array<string, mixed>|null  dane transakcji albo null, gdy P24 jej nie zna
     */
    public function findBySessionId(string $sessionId): ?array
    {
        try {
            $response = $this->client->get(
                Endpoints::build(Endpoints::TRANSACTION_BY_SESSION_ID, ['sessionId' => $sessionId])
            );
        } catch (ApiException $exception) {
            // Nieznana sesja to zwykła sytuacja: klient mógł przerwać
            // płatność, zanim P24 cokolwiek o niej zapisało.
            $this->logger->info('P24 nie zna tej sesji płatności', [
                'sessionId' => $sessionId,
                'http'      => $exception->getHttpStatus(),
            ]);

            return null;
        }

        return $response->data;
    }

    /**
     * Sprawdza, czy dane dostępowe są przyjmowane przez P24.
     *
     * Używane w panelu, żeby sprzedawca nie odkrywał literówki w kluczu
     * dopiero na pierwszym prawdziwym zamówieniu.
     */
    public function testAccess(): bool
    {
        try {
            $this->client->get(Endpoints::TEST_ACCESS);

            return true;
        } catch (ApiException $exception) {
            $this->logger->error('Dane dostępowe P24 odrzucone', [
                'http' => $exception->getHttpStatus(),
            ]);

            return false;
        }
    }
}
