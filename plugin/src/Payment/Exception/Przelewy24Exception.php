<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception;

\defined('_JEXEC') or die;

/**
 * Wspólny przodek wyjątków wtyczki.
 *
 * Komunikaty tych wyjątków trafiają do logu, nigdy wprost do klienta,
 * więc nie wolno umieszczać w nich sekretów konfiguracji.
 */
class Przelewy24Exception extends \RuntimeException
{
}
