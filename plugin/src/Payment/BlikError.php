<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Przyczyny odrzucenia płatności BLIK.
 *
 * P24 zwraca numer, a klientowi trzeba powiedzieć po ludzku, co robić
 * dalej. Rozróżnienie ma znaczenie praktyczne: przy jednych błędach
 * wolno poprosić o nowy kod, przy innych nie ma sensu, bo problem jest
 * po stronie banku albo środków.
 */
enum BlikError: string
{
    case WrongCode         = 'wrong_code';
    case CodeExpired       = 'code_expired';
    case CodeUsed          = 'code_used';
    case CodeStatus        = 'code_status';
    case InsufficientFunds = 'insufficient_funds';
    case LimitExceeded     = 'limit_exceeded';
    case IssuerDeclined    = 'issuer_declined';
    case BadPin            = 'bad_pin';
    case UserTimeout       = 'user_timeout';
    case Timeout           = 'timeout';
    case AliasDeclined     = 'alias_declined';
    case AliasNotFound     = 'alias_not_found';
    case SystemError       = 'system_error';
    case GeneralError      = 'general_error';

    /**
     * Odwzorowanie kodów P24 na przyczyny.
     *
     * Wartości wzięte z oficjalnej wtyczki Przelewów24 dla WooCommerce,
     * bo dokumentacja API ich nie wylicza.
     *
     * @var array<int, string>
     */
    private const CODES = [
        4  => 'system_error',
        20 => 'alias_not_found',
        24 => 'system_error',
        26 => 'system_error',
        28 => 'wrong_code',
        29 => 'wrong_code',
        30 => 'code_expired',
        33 => 'code_status',
        35 => 'code_used',
        39 => 'system_error',
        49 => 'alias_declined',
        52 => 'alias_not_found',
        60 => 'limit_exceeded',
        61 => 'insufficient_funds',
        62 => 'issuer_declined',
        63 => 'issuer_declined',
        64 => 'issuer_declined',
        65 => 'wrong_code',
        66 => 'bad_pin',
        67 => 'system_error',
        68 => 'alias_declined',
        69 => 'timeout',
        70 => 'user_timeout',
        71 => 'general_error',
        72 => 'timeout',
    ];

    public static function fromCode(mixed $code): self
    {
        if (!is_numeric($code)) {
            return self::GeneralError;
        }

        $nazwa = self::CODES[(int) $code] ?? null;

        return $nazwa === null ? self::GeneralError : (self::tryFrom($nazwa) ?? self::GeneralError);
    }

    /**
     * Czy warto poprosić klienta o nowy kod BLIK.
     *
     * Przy braku środków albo odmowie banku nowy kod niczego nie zmieni,
     * więc lepiej odesłać klienta do innej metody płatności.
     */
    public function allowsRetry(): bool
    {
        return match ($this) {
            self::WrongCode, self::CodeExpired, self::CodeUsed,
            self::CodeStatus, self::UserTimeout, self::Timeout => true,
            default => false,
        };
    }

    /**
     * Czy kod jednorazowy został już zużyty po stronie P24.
     *
     * Wtedy nie wolno wysyłać go drugi raz: możliwe, że obciążenie
     * doszło do skutku, a tylko odpowiedź do nas nie dotarła.
     */
    public function isCodeConsumed(): bool
    {
        return $this === self::CodeUsed;
    }

    public function languageKey(): string
    {
        return 'PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_ERROR_' . strtoupper($this->value);
    }
}
