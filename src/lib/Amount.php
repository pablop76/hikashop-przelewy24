<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

\defined('_JEXEC') or die;

/**
 * Przeliczanie kwot na najmniejszą jednostkę waluty.
 *
 * P24 przyjmuje kwoty wyłącznie jako liczby całkowite groszy. Naiwne
 * round($cena, 2) * 100 daje dla około 9% kwot liczbę zmiennoprzecinkową
 * (1,15 zł daje 114.99999999999999), którą json_encode wysyła dosłownie.
 * Mnożymy więc przed zaokrągleniem i rzutujemy na int.
 */
final class Amount
{
    private function __construct()
    {
    }

    /**
     * Zamienia kwotę na grosze.
     *
     * @param  int|float|string  $amount          kwota w jednostce głównej
     * @param  int               $fractionDigits  liczba miejsc po przecinku w walucie
     */
    public static function toMinorUnit(int|float|string $amount, int $fractionDigits = 2): int
    {
        if ($fractionDigits < 0 || $fractionDigits > 4) {
            throw new \InvalidArgumentException('Nieobsługiwana liczba miejsc dziesiętnych waluty: ' . $fractionDigits);
        }

        return (int) round((float) $amount * (10 ** $fractionDigits));
    }

    /**
     * Zamienia grosze z powrotem na kwotę w jednostce głównej.
     */
    public static function fromMinorUnit(int $minorUnits, int $fractionDigits = 2): float
    {
        return round($minorUnits / (10 ** $fractionDigits), $fractionDigits);
    }

    /**
     * Porównuje dwie kwoty w groszach.
     *
     * Osobna metoda, bo porównanie kwot decyduje o uznaniu płatności
     * i nie wolno go robić na liczbach zmiennoprzecinkowych.
     */
    public static function equals(int $first, int $second): bool
    {
        return $first === $second;
    }
}
