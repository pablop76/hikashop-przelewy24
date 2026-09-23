<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception;

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikError;

\defined('_JEXEC') or die;

/**
 * Płatność BLIK odrzucona przez P24 albo przez bank klienta.
 *
 * Niesie przyczynę, bo od niej zależy, czy wolno poprosić klienta
 * o nowy kod, czy trzeba go odesłać do innej metody płatności.
 */
final class BlikException extends Przelewy24Exception
{
    public function __construct(
        string $message,
        private readonly BlikError $reason = BlikError::GeneralError,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getReason(): BlikError
    {
        return $this->reason;
    }

    public function allowsRetry(): bool
    {
        return $this->reason->allowsRetry();
    }

    public function isCodeConsumed(): bool
    {
        return $this->reason->isCodeConsumed();
    }
}
