<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

\defined('_JEXEC') or die;

/**
 * Stan zwrotu zgłoszonego do P24.
 *
 * Zwrot rzadko kończy się od razu. Zwykle trafia do kolejki, a czasem
 * czeka na decyzję operatora, dlatego samo przyjęcie zgłoszenia przez
 * P24 nie oznacza, że pieniądze wróciły do klienta.
 */
enum RefundStatus: int
{
    case Completed = 1;
    case Pending   = 2;
    case ToConfirm = 3;
    case Rejected  = 4;

    public static function fromApi(mixed $value): ?self
    {
        if (!is_numeric($value)) {
            return null;
        }

        return self::tryFrom((int) $value);
    }

    /**
     * Czy pieniądze na pewno wróciły do klienta.
     */
    public function isFinished(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Czy zwrot przepadł i trzeba go zgłosić od nowa.
     */
    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    /**
     * Czy czekamy na rozstrzygnięcie.
     */
    public function isPending(): bool
    {
        return $this === self::Pending || $this === self::ToConfirm;
    }

    /**
     * Klucz językowy opisu dla sprzedawcy.
     */
    public function languageKey(): string
    {
        return match ($this) {
            self::Completed => 'PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_COMPLETED',
            self::Pending   => 'PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_PENDING',
            self::ToConfirm => 'PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_TO_CONFIRM',
            self::Rejected  => 'PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_REJECTED',
        };
    }
}
