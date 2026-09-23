<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

\defined('_JEXEC') or die;

/**
 * Autoloader klas wtyczki.
 *
 * HikaShop ładuje wtyczki płatności przez require_once na jednym pliku
 * (hikashop_import w helpers/helper.php), więc kontener Joomli nigdy nie
 * dostaje tej wtyczki do ręki i nie ma kto zarejestrować przestrzeni nazw.
 * Robimy to sami, raz, przy pierwszym załadowaniu pliku wtyczki.
 */
final class Autoloader
{
    private const NAMESPACE_PREFIX = 'WebService\\Przelewy24\\';

    private static bool $registered = false;

    private function __construct()
    {
    }

    public static function register(string $libraryPath): void
    {
        if (self::$registered) {
            return;
        }

        $basePath = rtrim($libraryPath, '/\\') . \DIRECTORY_SEPARATOR;
        $realBase = realpath($basePath);

        if ($realBase === false) {
            return;
        }

        $realBase = rtrim($realBase, '/\\') . \DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($realBase): void {
            if (!str_starts_with($class, self::NAMESPACE_PREFIX)) {
                return;
            }

            $relative = substr($class, \strlen(self::NAMESPACE_PREFIX));
            $path     = $realBase . str_replace('\\', \DIRECTORY_SEPARATOR, $relative) . '.php';

            // realpath chroni przed wyjściem poza katalog biblioteki,
            // gdyby nazwa klasy zawierała cokolwiek nieoczekiwanego.
            $resolved = realpath($path);

            if ($resolved !== false && str_starts_with($resolved, $realBase)) {
                require_once $resolved;
            }
        });

        self::$registered = true;
    }
}
