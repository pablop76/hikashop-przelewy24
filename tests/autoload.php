<?php
/**
 * Autoloader dla testow uruchamianych bez Joomli.
 *
 * W dzialajacej witrynie klasy wtyczki laduje Joomla, na podstawie
 * tagu <namespace path="src"> z manifestu. Testy jednostkowe chodza
 * bez Joomli, wiec to samo odwzorowanie rejestrujemy tutaj.
 */

spl_autoload_register(static function (string $klasa): void {
    $przedrostek = 'Pablop76\\Plugin\\HikashopPayment\\Przelewy24\\';

    if (!str_starts_with($klasa, $przedrostek)) {
        return;
    }

    $wzgledna = substr($klasa, strlen($przedrostek));
    $sciezka  = __DIR__ . '/../plugin/src/' . str_replace('\\', '/', $wzgledna) . '.php';

    if (is_file($sciezka)) {
        require_once $sciezka;
    }
});
