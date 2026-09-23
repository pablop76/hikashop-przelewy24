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

const _JEXEC = 1;

define('JPATH_BASE', getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap');

if (!is_file(JPATH_BASE . '/includes/defines.php')) {
    fwrite(STDERR, 'Nie znaleziono Joomli w ' . JPATH_BASE . PHP_EOL);
    fwrite(STDERR, 'Ustaw zmienna JOOMLA_PATH na katalog instalacji.' . PHP_EOL);
    exit(2);
}

require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$container = Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias('JSession', 'session.cli')
    ->alias(Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\Session::class, 'session.cli')
    ->alias(Joomla\Session\SessionInterface::class, 'session.cli');

$app = $container->get(Joomla\Console\Application::class);
Joomla\CMS\Factory::$application = $app;

// HikaShop uzywa starych nazw klas (JFactory, JText, JHtml). W zadaniu
// przegladarkowym rejestruje je wtyczka "Behaviour - Backward Compatibility",
// ktora w trybie CLI sie nie uruchamia, wiec wczytujemy jej mape aliasow.
require_once JPATH_PLUGINS . '/behaviour/compat/src/classmap/classmap.php';

// Mape przestrzeni nazw rozszerzen aplikacja konsolowa tworzy dopiero
// w execute(), ktorego tu nie wolamy. Bez niej HikaShop wywraca sie przy
// imporcie wtyczek z przestrzeniami nazw, na przyklad inpost_hika.
$app->createExtensionNamespaceMap();

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

// HikaShop udostepnia swoje funkcje pomocnicze przez ten plik.
$helper = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

if (!is_file($helper)) {
    fwrite(STDERR, 'Nie znaleziono helpera HikaShopa.' . PHP_EOL);
    exit(2);
}

require_once $helper;

sprawdz('funkcje HikaShopa dostepne', function_exists('hikashop_import'));

echo PHP_EOL . '1. Ladowanie wtyczki przez hikashop_import()' . PHP_EOL;

$wtyczka = hikashop_import('hikashoppayment', 'przelewy24');

sprawdz('hikashop_import zwrocil obiekt', is_object($wtyczka), is_object($wtyczka) ? get_class($wtyczka) : 'null');

if (!is_object($wtyczka)) {
    echo PHP_EOL . 'Dalsze testy nie maja sensu bez obiektu wtyczki.' . PHP_EOL;
    exit(1);
}

sprawdz('klasa nazywa sie zgodnie z wymogiem HikaShopa', get_class($wtyczka) === 'plgHikashoppaymentPrzelewy24');
sprawdz('dziedziczy po hikashopPaymentPlugin', $wtyczka instanceof hikashopPaymentPlugin);
sprawdz('nazwa metody platnosci', $wtyczka->name === 'przelewy24');
sprawdz('obsluguje PLN', in_array('PLN', $wtyczka->accepted_currencies, true));

echo PHP_EOL . '2. Autoloader biblioteki' . PHP_EOL;

$klasy = [
    'WebService\Przelewy24\Amount',
    'WebService\Przelewy24\ApiClient',
    'WebService\Przelewy24\Config',
    'WebService\Przelewy24\Environment',
    'WebService\Przelewy24\Logger',
    'WebService\Przelewy24\Notification',
    'WebService\Przelewy24\OrderPaymentData',
    'WebService\Przelewy24\RegisterRequest',
    'WebService\Przelewy24\SessionId',
    'WebService\Przelewy24\Signature',
    'WebService\Przelewy24\TransactionService',
    'WebService\Przelewy24\Exception\ApiException',
];

foreach ($klasy as $klasa) {
    sprawdz('klasa ' . $klasa, class_exists($klasa));
}

echo PHP_EOL . '3. Zachowanie w srodowisku Joomli' . PHP_EOL;

$config = WebService\Przelewy24\Config::fromPaymentParams(null);
sprawdz('pusta konfiguracja celuje w sandbox', $config->environment->isSandbox());
sprawdz('pusta konfiguracja jest niekompletna', !$config->isComplete());

$amount = WebService\Przelewy24\Amount::toMinorUnit(1.15);
sprawdz('przeliczanie groszy dziala pod Joomla', $amount === 115, $amount . ' gr');

$sesja = WebService\Przelewy24\SessionId::generate(7);
sprawdz('generowanie sessionId dziala', WebService\Przelewy24\SessionId::isValid($sesja));

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
