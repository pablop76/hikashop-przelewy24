<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 * @link        https://www.web-service.com.pl
 *
 * Most zgodności z HikaShopem.
 *
 * Właściwa klasa wtyczki żyje w przestrzeni nazw, w src/Extension.
 * HikaShop ładuje jednak wtyczki płatności po swojemu: funkcja
 * hikashop_import() robi require_once na tym pliku i od razu tworzy
 * obiekt klasy plgHikashoppaymentPrzelewy24, z pominięciem kontenera
 * Joomli. Alias poniżej sprawia, że obie drogi prowadzą do tej samej
 * klasy: i ta przez kontener, i ta przez HikaShopa.
 */

use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;

defined('_JEXEC') or die('Restricted access');

if (!class_exists('plgHikashoppaymentPrzelewy24', false)) {
    class_alias(Przelewy24::class, 'plgHikashoppaymentPrzelewy24');
}
