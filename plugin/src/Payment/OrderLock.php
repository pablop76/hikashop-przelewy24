<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Blokada zamówienia na czas zmiany jego danych płatności.
 *
 * Powiadomienie z P24 i klient wracający z bramki przychodzą zwykle w tej
 * samej sekundzie. Bez blokady oba żądania mogły jednocześnie uznać, że
 * zapłata nie jest jeszcze potwierdzona: status zmieniał się dwa razy,
 * a klient dostawał dwa e-maile. Ten sam wyścig gubił dane, bo pole
 * order_payment_params zapisuje się w całości (odczyt, scalenie, zapis)
 * i drugi zapis nadpisywał pierwszy, na przykład sesję nowej próby zapłaty.
 *
 * Blokada nazwana serwera bazy (GET_LOCK) działa między procesami PHP
 * i znika sama z końcem połączenia, także po awarii. HikaShop działa
 * wyłącznie na MySQL i MariaDB. Gdy blokady nie da się wziąć, praca idzie
 * bez niej, czyli tak jak do 1.0.10: wstrzymanie zapłaty byłoby gorsze
 * niż rzadki podwójny e-mail.
 */
final class OrderLock
{
    /** Ile sekund czekamy, aż inne żądanie skończy pracę na zamówieniu. */
    public const TIMEOUT_SECONDS = 10;

    /**
     * Blokady wzięte przez ten proces.
     *
     * Zapis danych płatności wołany jest także spod blokady zmiany statusu.
     * W bazie bierzemy ją raz: ponowne GET_LOCK zachowuje się różnie
     * w różnych wersjach MySQL i MariaDB.
     *
     * @var array<string, true>
     */
    private static array $wziete = [];

    private function __construct()
    {
    }

    /**
     * Wykonuje pracę, gdy żadne inne żądanie nie pracuje na tym zamówieniu.
     *
     * @template T
     *
     * @param  callable(): T  $praca
     *
     * @return T
     */
    public static function run(int $orderId, callable $praca, ?Logger $logger = null): mixed
    {
        $db    = self::database();
        $nazwa = self::name($orderId);

        // Bez bazy (testy bez Joomli) albo pod blokadą tego zamówienia już jesteśmy.
        if ($db === null || $nazwa === '' || isset(self::$wziete[$nazwa])) {
            return $praca();
        }

        if (!self::acquire($db, $nazwa)) {
            $logger?->warning('Nie udało się zablokować zamówienia, pracuję bez blokady', ['order_id' => $orderId]);

            return $praca();
        }

        self::$wziete[$nazwa] = true;

        try {
            return $praca();
        } finally {
            unset(self::$wziete[$nazwa]);
            self::release($db, $nazwa);
        }
    }

    /**
     * Nazwa blokady zamówienia, jednakowa dla wszystkich żądań witryny.
     *
     * Przedrostek tabel odróżnia witryny na wspólnym serwerze bazy.
     * Pusty ciąg, gdy nie ma bazy.
     */
    public static function name(int $orderId): string
    {
        $db = self::database();

        if ($db === null || $orderId <= 0) {
            return '';
        }

        return 'p24_' . $db->getPrefix() . 'order_' . $orderId;
    }

    private static function acquire(DatabaseInterface $db, string $nazwa): bool
    {
        try {
            $db->setQuery('SELECT GET_LOCK(' . $db->quote($nazwa) . ', ' . self::TIMEOUT_SECONDS . ')');

            return (string) $db->loadResult() === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    private static function release(DatabaseInterface $db, string $nazwa): void
    {
        try {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($nazwa) . ')');
            $db->loadResult();
        } catch (\Throwable) {
            // Blokadę i tak zwolni koniec połączenia.
        }
    }

    private static function database(): ?DatabaseInterface
    {
        if (!class_exists(Factory::class)) {
            return null;
        }

        try {
            return Factory::getContainer()->get(DatabaseInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
