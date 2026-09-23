<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

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

    public const REFUND_REQUEST_ID = 'p24_refund_request_id';
    public const REFUND_AMOUNT     = 'p24_refund_amount';
    public const REFUND_STATUS     = 'p24_refund_status';

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
     * Zapisuje dane nowej próby zapłaty i zwiększa licznik prób.
     */
    public static function startAttempt(
        int $orderId,
        string $sessionId,
        int $amountInMinorUnits,
        string $currency,
        int $previousAttempts
    ): bool {
        return self::store($orderId, [
            self::SESSION_ID => $sessionId,
            self::AMOUNT     => $amountInMinorUnits,
            self::CURRENCY   => $currency,
            self::ATTEMPTS   => $previousAttempts + 1,
            // Token i identyfikator P24 dotyczą poprzedniej próby,
            // więc przy nowej muszą zniknąć.
            self::TOKEN        => '',
            self::P24_ORDER_ID => 0,
        ]);
    }
}
