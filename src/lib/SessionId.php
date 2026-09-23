<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

\defined('_JEXEC') or die;

/**
 * Identyfikator sesji płatności.
 *
 * P24 wymaga, żeby sessionId był niepowtarzalny. Wysyłanie tu numeru
 * zamówienia (tak robi wtyczka IgnisDev) sprawia, że druga próba zapłaty
 * za to samo zamówienie zostaje odrzucona, klient nie dostaje tokenu
 * i nie ma jak ponowić płatności.
 *
 * Dlatego każda próba dostaje własny identyfikator, a powiązanie
 * z zamówieniem trzymamy po stronie sklepu.
 */
final class SessionId
{
    /** Limit długości pola sessionId w API P24. */
    public const MAX_LENGTH = 100;

    private function __construct()
    {
    }

    /**
     * Tworzy identyfikator nowej próby zapłaty za zamówienie.
     *
     * Przedrostek z numerem zamówienia zostaje, bo bardzo ułatwia
     * odnalezienie transakcji w panelu P24. Za jednoznaczność odpowiada
     * losowa część, nie numer zamówienia.
     */
    public static function generate(int $orderId): string
    {
        $unique = bin2hex(random_bytes(12));

        return substr('hika_' . $orderId . '_' . $unique, 0, self::MAX_LENGTH);
    }

    /**
     * Czy wartość nadaje się na sessionId.
     */
    public static function isValid(string $sessionId): bool
    {
        return $sessionId !== ''
            && \strlen($sessionId) <= self::MAX_LENGTH
            && preg_match('/^[A-Za-z0-9_.-]+$/', $sessionId) === 1;
    }
}
