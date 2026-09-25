<?php
/**
 * Wspolny rozruch Joomli i HikaShopa dla testow integracyjnych.
 *
 * Dolaczany przez tests/joomla.php, tests/notification.php i tests/refund.php.
 * Sciezke do Joomli mozna podac zmienna srodowiskowa JOOMLA_PATH.
 *
 * Uzywamy aplikacji witryny, a nie konsolowej: powiadomienia z P24
 * przychodza jako zwykle zadania do witryny, a HikaShop korzysta
 * z setUserState(), ktorego aplikacja konsolowa nie ma.
 */

if (!defined('_JEXEC')) {
    define('_JEXEC', 1);
}

if (!defined('JPATH_BASE')) {
    define('JPATH_BASE', getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap');
}

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

$app = $container->get(Joomla\CMS\Application\SiteApplication::class);
Joomla\CMS\Factory::$application = $app;

// Jezyk aplikacji ustawia sie dopiero w execute(), ktorego tu nie wolamy.
// Bez tego getLanguage() zwraca null, a HikaShop wywraca sie na load()
// przy kazdej zmianie statusu zamowienia.
$app->loadLanguage(
    Joomla\CMS\Language\Language::getInstance(
        $container->get('config')->get('language', 'en-GB')
    )
);

// HikaShop uzywa starych nazw klas (JFactory, JText, JHtml). W zadaniu
// przegladarkowym rejestruje je wtyczka "Behaviour - Backward Compatibility",
// ktora poza przegladarka sie nie uruchamia. Joomla 5 ma ja w "compat",
// Joomla 6 w "compat6".
foreach (['compat', 'compat6'] as $compat) {
    $classmap = JPATH_PLUGINS . '/behaviour/' . $compat . '/src/classmap/classmap.php';

    if (is_file($classmap)) {
        require_once $classmap;
        break;
    }
}

// Mape przestrzeni nazw rozszerzen aplikacja tworzy dopiero w execute(),
// ktorego tu nie wolamy. Bez niej HikaShop wywraca sie przy imporcie
// wtyczek zbudowanych na przestrzeniach nazw.
$app->createExtensionNamespaceMap();

$helperHikaShop = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

if (!is_file($helperHikaShop)) {
    fwrite(STDERR, 'Nie znaleziono HikaShopa w ' . JPATH_BASE . PHP_EOL);
    exit(2);
}

require_once $helperHikaShop;

/**
 * Polaczenie z baza witryny.
 */
function polaczenieZBaza(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $cfg = new JConfig();
    $pdo = new PDO(
        "mysql:host={$cfg->host};dbname={$cfg->db};charset=utf8mb4",
        $cfg->user,
        $cfg->password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function przedrostekTabel(): string
{
    return (new JConfig())->dbprefix;
}

/**
 * Konfiguracja P24 odczytana z opublikowanej metody platnosci.
 *
 * @return array{id: int, config: Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config}
 */
function metodaPlatnosciP24(): array
{
    $prefix = przedrostekTabel();
    $q      = polaczenieZBaza()->query(
        "SELECT payment_id, payment_params FROM {$prefix}hikashop_payment
         WHERE payment_type = 'przelewy24' AND payment_published = 1 LIMIT 1"
    );
    $wiersz = $q->fetch(PDO::FETCH_ASSOC);

    if (!$wiersz) {
        fwrite(STDERR, 'Brak opublikowanej metody platnosci przelewy24 w HikaShopie.' . PHP_EOL);
        exit(2);
    }

    $parametry = (object) (array) @unserialize($wiersz['payment_params']);
    $config    = Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config::fromPaymentParams($parametry);

    if (!$config->isComplete()) {
        fwrite(STDERR, 'Metoda platnosci nie ma kompletu danych P24.' . PHP_EOL);
        exit(2);
    }

    return ['id' => (int) $wiersz['payment_id'], 'config' => $config];
}
