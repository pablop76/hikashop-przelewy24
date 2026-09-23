<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24\Exception;

\defined('_JEXEC') or die;

/**
 * Podpis powiadomienia nie zgadza się z wyliczonym po stronie sklepu.
 *
 * Oznacza, że żądanie nie pochodzi od P24 albo nie dotyczy tej transakcji.
 * Zamówienia nie wolno wtedy ruszyć.
 */
final class SignatureException extends Przelewy24Exception
{
}
