<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception;

\defined('_JEXEC') or die;

/**
 * Wtyczka nie ma kompletu danych dostępowych do P24.
 */
final class ConfigurationException extends Przelewy24Exception
{
}
