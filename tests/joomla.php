<?php
/**
 * Sprawdza, czy HikaShop potrafi zaladowac wtyczke przelewy24
 * w prawdziwym srodowisku Joomli.
 *
 * Uruchomienie:
 *   php tests/joomla.php
 *   JOOMLA_PATH=D:/sciezka/do/joomli php tests/joomla.php
 *
 * Wymaga wczesniejszej instalacji paczki w tej instalacji Joomli.
 * Skrypt lezy poza katalogiem witryny, wiec nie jest dostepny z przegladarki.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

$zdane = 0;
$bledy = 0;

function sprawdz(string $opis, bool $ok, string $szczegol = ''): void
{
    global $zdane, $bledy;

    if ($ok) {
        $zdane++;
        echo '  OK   ' . $opis . ($szczegol !== '' ? ' (' . $szczegol . ')' : '') . PHP_EOL;

        return;
    }

    $bledy++;
    echo '  BLAD ' . $opis . ($szczegol !== '' ? ': ' . $szczegol : '') . PHP_EOL;
}

echo 'Joomla ' . (new Joomla\CMS\Version())->getShortVersion() . PHP_EOL;

sprawdz('funkcje HikaShopa dostepne', function_exists('hikashop_import'));

echo PHP_EOL . '1. Ladowanie wtyczki przez hikashop_import()' . PHP_EOL;

$wtyczka = hikashop_import('hikashoppayment', 'przelewy24');

sprawdz('hikashop_import zwrocil obiekt', is_object($wtyczka), is_object($wtyczka) ? get_class($wtyczka) : 'null');

if (!is_object($wtyczka)) {
    echo PHP_EOL . 'Dalsze testy nie maja sensu bez obiektu wtyczki.' . PHP_EOL;
    exit(1);
}

// Obiekt jest klasa z przestrzeni nazw, a plgHikashoppaymentPrzelewy24
// to alias zakladany w przelewy24.php. HikaShop sprawdza istnienie tego
// aliasu przez class_exists() i po nim tworzy obiekt, wiec musi byc.
sprawdz(
    'klasa zyje w przestrzeni nazw',
    get_class($wtyczka) === 'Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24',
    get_class($wtyczka)
);
sprawdz('alias wymagany przez HikaShopa istnieje', class_exists('plgHikashoppaymentPrzelewy24', false));
sprawdz('obiekt odpowiada aliasowi', $wtyczka instanceof plgHikashoppaymentPrzelewy24);
sprawdz('dziedziczy po hikashopPaymentPlugin', $wtyczka instanceof hikashopPaymentPlugin);
sprawdz('nazwa metody platnosci', $wtyczka->name === 'przelewy24');
sprawdz('obsluguje PLN', in_array('PLN', $wtyczka->accepted_currencies, true));

echo PHP_EOL . '2. Autoloader biblioteki' . PHP_EOL;

$klasy = [
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Environment',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Notification',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RefundService',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RefundStatus',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Signature',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\TransactionService',
    'Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException',
];

foreach ($klasy as $klasa) {
    sprawdz('klasa ' . $klasa, class_exists($klasa));
}

echo PHP_EOL . '3. Zachowanie w srodowisku Joomli' . PHP_EOL;

$config = Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config::fromPaymentParams(null);
sprawdz('pusta konfiguracja celuje w sandbox', $config->environment->isSandbox());
sprawdz('pusta konfiguracja jest niekompletna', !$config->isComplete());

$amount = Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount::toMinorUnit(1.15);
sprawdz('przeliczanie groszy dziala pod Joomla', $amount === 115, $amount . ' gr');

$sesja = Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId::generate(7);
sprawdz('generowanie sessionId dziala', Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId::isValid($sesja));

echo PHP_EOL . '4. Metody wymagane przez HikaShopa' . PHP_EOL;

foreach (['onAfterOrderConfirm', 'onPaymentNotification', 'getPaymentDefaultValues', 'onPaymentConfiguration'] as $metoda) {
    sprawdz('metoda ' . $metoda . '()', method_exists($wtyczka, $metoda));
}

// Sygnatury metod on* musza byc bez typow, inaczej PHP odrzucilby klase
// przy dziedziczeniu. Skoro obiekt powstal, kontrawariancja jest zachowana.
$refleksja = new ReflectionMethod($wtyczka, 'onPaymentNotification');
sprawdz('onPaymentNotification przyjmuje referencje', $refleksja->getParameters()[0]->isPassedByReference());

echo PHP_EOL . '5. Widoki wtyczki' . PHP_EOL;

$katalog = JPATH_PLUGINS . '/hikashoppayment/przelewy24';

foreach (['przelewy24_end.php', 'przelewy24_configuration.php', 'przelewy24.xml'] as $plik) {
    sprawdz('plik ' . $plik, is_file($katalog . '/' . $plik));
}

echo PHP_EOL . '6. Tlumaczenia' . PHP_EOL;

$jezyk = Joomla\CMS\Factory::getApplication()->getLanguage();
$jezyk->load('plg_hikashoppayment_przelewy24', JPATH_ADMINISTRATOR);

$klucz = 'PLG_HIKASHOPPAYMENT_PRZELEWY24_GO_TO_GATEWAY';
$tekst = Joomla\CMS\Language\Text::_($klucz);
sprawdz('klucz jezykowy sie tlumaczy', $tekst !== $klucz, $tekst);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
