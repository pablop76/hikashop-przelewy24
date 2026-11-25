<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception;

\defined('_JEXEC') or die;

/**
 * Powiadomienie z poprawnym podpisem, ale z kwotą lub walutą inną niż zamówienie.
 *
 * Pochodzi od P24 i mówi o prawdziwej wpłacie, więc w odróżnieniu od złego
 * podpisu sprzedawca musi się o nim dowiedzieć. Zamówienia nadal nie wolno
 * ruszyć, dlatego dziedziczy po SignatureException.
 */
final class AmountMismatchException extends SignatureException
{
}
