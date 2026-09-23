<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Ścieżki REST API P24, względem adresu środowiska.
 *
 * @see https://developers.przelewy24.pl/#tag/Transaction-service-API
 */
final class Endpoints
{
    public const TRANSACTION_REGISTER      = 'api/v1/transaction/register';
    public const TRANSACTION_VERIFY        = 'api/v1/transaction/verify';
    public const TRANSACTION_DO_PAYMENT    = 'api/v1/transaction/doPayment';
    public const TRANSACTION_BY_SESSION_ID = 'api/v1/transaction/by/sessionId/{sessionId}';
    public const TRANSACTION_REJECT        = 'api/v1/transaction/reject';

    public const REFUND         = 'api/v1/transaction/refund';
    public const REFUND_DETAILS = 'api/v1/refund/by/orderId/{orderId}';

    public const PAYMENT_METHODS = 'api/v1/payment/methods/{lang}';
    public const CARD_INFO       = 'api/v1/card/info/{orderId}';

    public const BLIK_CHARGE_BY_CODE  = 'api/v1/paymentMethod/blik/chargeByCode';
    public const BLIK_CHARGE_BY_ALIAS = 'api/v1/paymentMethod/blik/chargeByAlias';

    public const TEST_ACCESS = 'api/v1/testAccess';

    /** Strona płatności, na którą przekierowujemy klienta po rejestracji. */
    public const PAYWALL = 'trnRequest/{token}';

    private function __construct()
    {
    }

    /**
     * Podstawia wartości w miejsce znaczników {nazwa} i koduje je do adresu.
     *
     * @param  array<string, string|int>  $parameters
     */
    public static function build(string $endpoint, array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $endpoint = str_replace('{' . $name . '}', rawurlencode((string) $value), $endpoint);
        }

        if (preg_match('/\{[a-zA-Z]+\}/', $endpoint, $matches) === 1) {
            throw new \InvalidArgumentException('Nie podstawiono parametru ' . $matches[0] . ' w adresie ' . $endpoint);
        }

        return $endpoint;
    }
}
