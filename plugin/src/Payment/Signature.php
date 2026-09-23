<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Sumy kontrolne P24 (SHA-384 z JSON-a o ściśle określonej kolejności kluczy).
 *
 * Kolejność kluczy jest częścią specyfikacji, nie kwestią stylu. Zmiana
 * kolejności psuje podpis, dlatego każdy wariant ma tu własną metodę
 * zamiast jednej metody z tablicą budowaną przez wywołującego.
 */
final class Signature
{
    private function __construct()
    {
    }

    /**
     * Podpis rejestracji transakcji (transaction/register).
     */
    public static function forRegister(
        string $sessionId,
        int $merchantId,
        int $amount,
        string $currency,
        string $crc
    ): string {
        return self::hash([
            'sessionId'  => $sessionId,
            'merchantId' => $merchantId,
            'amount'     => $amount,
            'currency'   => $currency,
            'crc'        => $crc,
        ]);
    }

    /**
     * Podpis weryfikacji transakcji (transaction/verify).
     */
    public static function forVerify(
        string $sessionId,
        int $p24OrderId,
        int $amount,
        string $currency,
        string $crc
    ): string {
        return self::hash([
            'sessionId' => $sessionId,
            'orderId'   => $p24OrderId,
            'amount'    => $amount,
            'currency'  => $currency,
            'crc'       => $crc,
        ]);
    }

    /**
     * Podpis, którym P24 podpisuje powiadomienie wysyłane na urlStatus.
     */
    public static function forNotification(
        int $merchantId,
        int $posId,
        string $sessionId,
        int $amount,
        int $originAmount,
        string $currency,
        int $p24OrderId,
        int $methodId,
        ?string $statement,
        string $crc
    ): string {
        return self::hash([
            'merchantId'   => $merchantId,
            'posId'        => $posId,
            'sessionId'    => $sessionId,
            'amount'       => $amount,
            'originAmount' => $originAmount,
            'currency'     => $currency,
            'orderId'      => $p24OrderId,
            'methodId'     => $methodId,
            'statement'    => $statement,
            'crc'          => $crc,
        ]);
    }

    /**
     * Podpis formularza karty osadzanego w sklepie.
     */
    public static function forCardForm(int $merchantId, string $sessionId, string $crc): string
    {
        return self::hash([
            'merchantId' => $merchantId,
            'sessionId'  => $sessionId,
            'crc'        => $crc,
        ]);
    }

    /**
     * Podpis kalkulatora rat.
     */
    public static function forInstallments(string $crc, int $posId, int $method): string
    {
        return self::hash([
            'crc'    => $crc,
            'posId'  => $posId,
            'method' => $method,
        ]);
    }

    /**
     * Porównanie podpisów odporne na atak czasowy.
     */
    public static function matches(string $expected, string $received): bool
    {
        return $received !== '' && hash_equals($expected, $received);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function hash(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha384', $json);
    }
}
