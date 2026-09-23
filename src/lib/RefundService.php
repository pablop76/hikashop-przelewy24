<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

use WebService\Przelewy24\Exception\ApiException;

\defined('_JEXEC') or die;

/**
 * Zwroty pełne i częściowe.
 *
 * P24 przyjmuje zwrot do realizacji i odpowiada jego stanem. Stan inny
 * niż zakończony nie jest błędem: zwrot bywa przetwarzany później albo
 * czeka na decyzję operatora. Dlatego ta klasa oddaje stan wywołującemu
 * zamiast zgadywać, czy pieniądze już wróciły.
 */
final class RefundService
{
    /** Limit długości pola requestId w API P24. */
    private const REQUEST_ID_MAX_LENGTH = 100;

    public function __construct(
        private readonly ApiClient $client,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    /**
     * Zgłasza zwrot dla jednej transakcji.
     *
     * @param  string  $sessionId           identyfikator sesji zapłaty
     * @param  int     $p24OrderId          identyfikator transakcji nadany przez P24
     * @param  int     $amountInMinorUnits  kwota zwrotu w groszach
     * @param  string  $description         opis widoczny w panelu P24
     * @param  string  $urlStatus           adres powiadomienia o zwrocie
     *
     * @return array{requestId: string, status: RefundStatus|null, surowe: array<string, mixed>}
     *
     * @throws ApiException
     */
    public function refund(
        string $sessionId,
        int $p24OrderId,
        int $amountInMinorUnits,
        string $description,
        string $urlStatus = ''
    ): array {
        if ($amountInMinorUnits <= 0) {
            throw new \InvalidArgumentException('Kwota zwrotu musi być dodatnia');
        }

        $requestId = self::generateRequestId($p24OrderId);

        $payload = [
            'requestId'   => $requestId,
            'refundsUuid' => self::generateRefundsUuid($p24OrderId),
            'refunds'     => [
                [
                    'orderId'     => $p24OrderId,
                    'sessionId'   => $sessionId,
                    'amount'      => $amountInMinorUnits,
                    'description' => mb_substr(trim($description), 0, 100),
                ],
            ],
        ];

        if ($urlStatus !== '') {
            $payload['urlStatus'] = $urlStatus;
        }

        $this->logger->info('Zgłaszam zwrot do P24', [
            'sessionId' => $sessionId,
            'p24_order' => $p24OrderId,
            'kwota_gr'  => $amountInMinorUnits,
            'requestId' => $requestId,
        ]);

        $response = $this->client->post(Endpoints::REFUND, $payload);

        $status = $this->readStatus($response->data, $requestId);

        $this->logger->info('P24 przyjęło zgłoszenie zwrotu', [
            'requestId' => $requestId,
            'stan'      => $status?->name ?? 'nieznany',
        ]);

        return [
            'requestId' => $requestId,
            'status'    => $status,
            'surowe'    => $response->data,
        ];
    }

    /**
     * Sprawdza stan zwrotów zgłoszonych dla danej transakcji.
     *
     * @return array<string, RefundStatus>  stan pod kluczem requestId
     */
    public function statusesFor(int $p24OrderId): array
    {
        try {
            $response = $this->client->get(
                Endpoints::build(Endpoints::REFUND_DETAILS, ['orderId' => $p24OrderId])
            );
        } catch (ApiException $exception) {
            $this->logger->info('P24 nie zna zwrotów dla tej transakcji', [
                'p24_order' => $p24OrderId,
                'http'      => $exception->getHttpStatus(),
            ]);

            return [];
        }

        $wynik  = [];
        $zwroty = $response->get('refunds', []);

        if (!\is_array($zwroty)) {
            return $wynik;
        }

        foreach ($zwroty as $zwrot) {
            $zwrot = (array) $zwrot;

            $requestId = isset($zwrot['requestId']) ? (string) $zwrot['requestId'] : '';
            $status    = RefundStatus::fromApi($zwrot['status'] ?? null);

            if ($requestId !== '' && $status !== null) {
                $wynik[$requestId] = $status;
            }
        }

        return $wynik;
    }

    /**
     * Wyławia stan zwrotu odpowiadający naszemu zgłoszeniu.
     *
     * @param  array<string, mixed>  $data
     */
    private function readStatus(array $data, string $requestId): ?RefundStatus
    {
        $zwroty = $data['refunds'] ?? null;

        if (!\is_array($zwroty)) {
            return null;
        }

        foreach ($zwroty as $zwrot) {
            $zwrot = (array) $zwrot;

            if (($zwrot['requestId'] ?? null) === $requestId) {
                return RefundStatus::fromApi($zwrot['status'] ?? null);
            }
        }

        // Część odpowiedzi nie powtarza requestId przy pojedynczym zwrocie.
        $pierwszy = (array) reset($zwroty);

        return RefundStatus::fromApi($pierwszy['status'] ?? null);
    }

    /**
     * Identyfikator zgłoszenia, po którym rozpoznajemy nasz zwrot
     * w odpowiedzi i w późniejszym powiadomieniu.
     */
    private static function generateRequestId(int $p24OrderId): string
    {
        return substr('hika_ref_' . $p24OrderId . '_' . bin2hex(random_bytes(8)), 0, self::REQUEST_ID_MAX_LENGTH);
    }

    /**
     * Identyfikator paczki zwrotów. P24 ogranicza to pole do 36 znaków.
     */
    private static function generateRefundsUuid(int $p24OrderId): string
    {
        return substr('hika_' . $p24OrderId . '_' . bin2hex(random_bytes(8)), 0, 36);
    }
}
