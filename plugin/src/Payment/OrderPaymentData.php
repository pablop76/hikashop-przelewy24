<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Dane transakcji P24 zapisane przy zamówieniu HikaShopa.
 *
 * HikaShop trzyma je w polu order_payment_params, które bywa zwracane
 * raz jako obiekt, a raz jako ciąg po serialize. Cały ten bałagan
 * zamykamy tutaj, żeby reszta wtyczki widziała zwykłe metody.
 *
 * Zapis zawsze scala z tym, co już w polu jest: inne wtyczki i sam
 * HikaShop również z niego korzystają i nie wolno ich nadpisać.
 */
final class OrderPaymentData
{
    public const SESSION_ID   = 'p24_session_id';
    public const TOKEN        = 'p24_token';
    public const P24_ORDER_ID = 'p24_order_id';
    public const AMOUNT       = 'p24_amount';
    public const CURRENCY     = 'p24_currency';
    public const METHOD_ID    = 'p24_method_id';
    public const VERIFIED_AT  = 'p24_verified_at';
    public const ATTEMPTS     = 'p24_attempts';

    /** Chwila, w której sprzedawca dostał alert o nieudanej weryfikacji. */
    public const VERIFY_ALERT_AT = 'p24_verify_alert_at';

    /** Wcześniejsze sesje płatności zamówienia, od najstarszej. */
    public const SESSIONS = 'p24_sessions';

    /** Sesja, której wpłatę zweryfikowaliśmy. */
    public const PAID_SESSION = 'p24_paid_session';

    /** Przyczyna odrzucenia płatności BLIK, zgłoszona przez bank. */
    public const BLIK_ERROR = 'p24_blik_error';

    /** Sesja, której to odrzucenie dotyczy. */
    public const BLIK_ERROR_SESSION = 'p24_blik_error_session';

    /** Ile wcześniejszych sesji pamiętamy przy zamówieniu. */
    public const SESSIONS_LIMIT = 10;

    private function __construct()
    {
    }

    /**
     * Zwraca parametry płatności zamówienia jako obiekt.
     */
    public static function read(?object $order): object
    {
        if ($order === null || !isset($order->order_payment_params)) {
            return new \stdClass();
        }

        $params = $order->order_payment_params;

        if (\is_string($params) && $params !== '') {
            // Bez klas: w tym polu mają być wyłącznie dane, a nie obiekty
            // do odtworzenia. Blokuje to podstawienie obiektu przez bazę.
            $unserialized = @unserialize($params, ['allowed_classes' => [\stdClass::class]]);
            $params       = ($unserialized === false) ? new \stdClass() : $unserialized;
        }

        if (\is_array($params)) {
            $params = (object) $params;
        }

        return \is_object($params) ? $params : new \stdClass();
    }

    public static function get(?object $order, string $key, mixed $default = null): mixed
    {
        $params = self::read($order);

        if (!isset($params->$key) || $params->$key === '') {
            return $default;
        }

        return $params->$key;
    }

    public static function getString(?object $order, string $key, string $default = ''): string
    {
        $value = self::get($order, $key, $default);

        return \is_scalar($value) ? (string) $value : $default;
    }

    public static function getInt(?object $order, string $key, int $default = 0): int
    {
        $value = self::get($order, $key, $default);

        return \is_scalar($value) ? (int) $value : $default;
    }

    /**
     * Dopisuje wartości do parametrów płatności zamówienia i zapisuje je.
     *
     * Parametry odczytujemy świeżo z bazy, bo między wczytaniem zamówienia
     * a tym zapisem mogło dojść powiadomienie od P24 i dopisać swoje pola.
     *
     * @param  array<string, mixed>  $values
     */
    public static function store(int $orderId, array $values): bool
    {
        if ($orderId <= 0 || $values === []) {
            return false;
        }

        if (!\function_exists('hikashop_get')) {
            return false;
        }

        $orderClass = hikashop_get('class.order');

        if (!\is_object($orderClass)) {
            return false;
        }

        $current = $orderClass->get($orderId);
        $params  = self::read($current);

        foreach ($values as $key => $value) {
            $params->$key = $value;
        }

        $update                        = new \stdClass();
        $update->order_id              = $orderId;
        $update->order_payment_params  = $params;

        return (bool) $orderClass->save($update);
    }

    /**
     * Zapisuje nową próbę zapłaty: jej sesję, kwotę i licznik prób.
     *
     * Każda próba ma własną sesję, czyli własną transakcję w P24. Po
     * nieudanej płatności P24 nie pozwala dokończyć tej samej transakcji:
     * jej strona płatności od razu odsyła klienta do sklepu. Sprawdzone na
     * sandboksie 09.10.2026.
     *
     * Sesja poprzedniej próby nie przepada, tylko przechodzi na listę
     * wcześniejszych. Wpłata za nią może jeszcze nadejść (przelew
     * tradycyjny, odnośnik z wiadomości P24) i powiadomienie o niej musi
     * pasować do zamówienia. Przed dublowaniem zapłaty chroni pytanie do
     * P24 o wszystkie sesje zamówienia, zadawane przed każdą nową próbą.
     *
     * Stan czytamy świeżo z bazy, a nie z obiektu zamówienia w pamięci, żeby
     * dwie próby rozpoczęte jedna po drugiej nie zgubiły sobie nawzajem sesji.
     */
    public static function startAttempt(
        int $orderId,
        string $sessionId,
        int $amountInMinorUnits,
        string $currency
    ): bool {
        $zapisane = self::fresh($orderId);

        return self::store($orderId, [
            self::SESSION_ID => $sessionId,
            self::SESSIONS   => self::earlierSessions($zapisane, $sessionId),
            self::AMOUNT     => $amountInMinorUnits,
            self::CURRENCY   => $currency,
            self::ATTEMPTS   => self::getInt($zapisane, self::ATTEMPTS) + 1,
        ]);
    }

    /**
     * Wszystkie sesje płatności zamówienia, od najnowszej.
     *
     * @return list<string>
     */
    public static function sessionsOf(?object $order): array
    {
        $sesje = array_reverse(self::history($order));

        array_unshift($sesje, self::getString($order, self::SESSION_ID));

        return array_values(array_unique(array_filter($sesje, [SessionId::class, 'isValid'])));
    }

    /**
     * Lista wcześniejszych sesji po rozpoczęciu nowej próby, od najstarszej.
     *
     * Dotychczasowa bieżąca sesja dołącza do listy. Najstarsze wpisy ponad
     * limit odpadają: wpłata za próbę sprzed kilkunastu ponowień nie zostanie
     * już przypisana do zamówienia i P24 zwróci ją klientowi.
     *
     * @return list<string>
     */
    public static function earlierSessions(?object $order, string $newSessionId): array
    {
        $sesje   = self::history($order);
        $sesje[] = self::getString($order, self::SESSION_ID);

        $sesje = array_values(array_unique(array_filter(
            $sesje,
            static fn (string $sesja): bool => $sesja !== $newSessionId && SessionId::isValid($sesja)
        )));

        return \array_slice($sesje, -self::SESSIONS_LIMIT);
    }

    /**
     * Zapisana lista wcześniejszych sesji, bez sprawdzania poprawności.
     *
     * @return list<string>
     */
    private static function history(?object $order): array
    {
        $zapisane = self::get($order, self::SESSIONS, []);

        if (\is_object($zapisane)) {
            $zapisane = (array) $zapisane;
        }

        if (!\is_array($zapisane)) {
            return [];
        }

        return array_values(array_map('strval', array_filter($zapisane, 'is_scalar')));
    }

    /**
     * Zamówienie odczytane świeżo z bazy.
     */
    private static function fresh(int $orderId): ?object
    {
        if ($orderId <= 0 || !\function_exists('hikashop_get')) {
            return null;
        }

        $orderClass = hikashop_get('class.order');

        if (!\is_object($orderClass)) {
            return null;
        }

        $order = $orderClass->get($orderId);

        return \is_object($order) ? $order : null;
    }
}
