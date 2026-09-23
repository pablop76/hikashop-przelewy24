<?php
/**
 * Test pelnej sciezki powiadomienia P24 w zainstalowanej Joomli.
 *
 * Uruchomienie:
 *   php tests/notification.php
 *
 * To najgrozniejszy kod w calej wtyczce, bo decyduje o uznaniu zaplaty.
 * Siec jest podstawiona atrapa transportu HTTP, ale wszystko pozostale
 * dzieje sie naprawde: prawdziwe zamowienie w bazie, prawdziwa metoda
 * platnosci, prawdziwe podpisy i prawdziwa zmiana statusu przez HikaShopa.
 *
 * Zamowienie testowe powstaje bez pozycji, zeby nie ruszac stanow
 * magazynowych, i jest kasowane na koncu niezaleznie od wyniku.
 */

const _JEXEC = 1;

define('JPATH_BASE', getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap');

if (!is_file(JPATH_BASE . '/includes/defines.php')) {
    fwrite(STDERR, 'Nie znaleziono Joomli w ' . JPATH_BASE . PHP_EOL);
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

// Aplikacja witryny, a nie konsolowa: powiadomienie z P24 przychodzi
// jako zwykle zadanie do witryny, a HikaShop korzysta z setUserState(),
// ktorego aplikacja konsolowa nie ma.
$app = $container->get(Joomla\CMS\Application\SiteApplication::class);
Joomla\CMS\Factory::$application = $app;

require_once JPATH_PLUGINS . '/behaviour/compat/src/classmap/classmap.php';
$app->createExtensionNamespaceMap();

require_once JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Http\TransportInterface;
use Joomla\Uri\UriInterface;
use Laminas\Diactoros\Stream;
use WebService\Przelewy24\ApiClient;
use WebService\Przelewy24\Config;
use WebService\Przelewy24\Logger;
use WebService\Przelewy24\OrderPaymentData;
use WebService\Przelewy24\SessionId;
use WebService\Przelewy24\Signature;
use WebService\Przelewy24\TransactionService;

$zdane = 0;
$bledy = 0;

function wynik(string $opis, bool $ok, string $szczegol = ''): void
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

/**
 * Atrapa transportu HTTP: zwraca przygotowana odpowiedz zamiast
 * odzywac sie do P24. Zapamietuje wywolania, zeby dalo sie sprawdzic,
 * czy weryfikacja w ogole zostala wykonana.
 */
final class TransportAtrapa implements TransportInterface
{
    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    public function __construct(
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"status":"success"}}'
    ) {
    }

    public function request($method, UriInterface $uri, $data = null, array $headers = [], $timeout = null, $userAgent = null)
    {
        $this->wywolania[] = [
            'metoda' => $method,
            'adres'  => (string) $uri,
            'tresc'  => $data,
        ];

        $strumien = new Stream('php://memory', 'rw');
        $strumien->write($this->odpowiedz);

        return new Response($strumien, $this->kodHttp);
    }

    public static function isSupported()
    {
        return true;
    }
}

/**
 * Wtyczka z podstawiona trescia powiadomienia i podstawionym transportem.
 */
final class WtyczkaTestowa extends plgHikashoppaymentPrzelewy24
{
    public string $trescPowiadomienia = '';

    public ?TransportAtrapa $transport = null;

    protected function readNotificationBody()
    {
        return $this->trescPowiadomienia;
    }

    protected function buildService(Config $config, Logger $logger)
    {
        $http   = new Http([], $this->transport);
        $client = new ApiClient($config, $logger, self::VERSION, 'https://haskap.test', $http);

        return new TransactionService($client, $config, $logger);
    }
}

// --- przygotowanie ---------------------------------------------------

$cfg = new JConfig();
$pdo = new PDO("mysql:host={$cfg->host};dbname={$cfg->db};charset=utf8mb4", $cfg->user, $cfg->password);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = $cfg->dbprefix;

$q = $pdo->query("SELECT payment_id, payment_params FROM {$prefix}hikashop_payment WHERE payment_type = 'przelewy24' AND payment_published = 1 LIMIT 1");
$metoda = $q->fetch(PDO::FETCH_ASSOC);

if (!$metoda) {
    fwrite(STDERR, 'Brak opublikowanej metody platnosci przelewy24 w HikaShopie.' . PHP_EOL);
    exit(2);
}

$parametry = (object) (array) @unserialize($metoda['payment_params']);
$config    = Config::fromPaymentParams($parametry);

if (!$config->isComplete()) {
    fwrite(STDERR, 'Metoda platnosci nie ma kompletu danych P24.' . PHP_EOL);
    exit(2);
}

echo 'Metoda platnosci: id=' . $metoda['payment_id'] . ', srodowisko ' . $config->environment->value . PHP_EOL;

// Zamowienie testowe: bez pozycji, wiec bez wplywu na magazyn.
$kwotaZl     = 11.50;
$kwotaGrosze = 1150;
$sessionId   = SessionId::generate(999999);
$orderToken  = bin2hex(random_bytes(16));
$p24OrderId  = 555000111;

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_order
        (order_number, order_created, order_modified, order_status, order_type,
         order_full_price, order_currency_id, order_payment_id, order_payment_method,
         order_token, order_payment_params)
    VALUES
        (:numer, :utworzone, :utworzone, 'created', 'sale',
         :cena, 125, :platnosc, 'przelewy24',
         :token, :parametry)
")->execute([
    ':numer'     => 'P24TEST' . random_int(1000, 9999),
    ':utworzone' => time(),
    ':cena'      => $kwotaZl,
    ':platnosc'  => $metoda['payment_id'],
    ':token'     => $orderToken,
    ':parametry' => serialize((object) [
        OrderPaymentData::SESSION_ID => $sessionId,
        OrderPaymentData::AMOUNT     => $kwotaGrosze,
        OrderPaymentData::CURRENCY   => 'PLN',
        OrderPaymentData::ATTEMPTS   => 1,
    ]),
]);

$orderId = (int) $pdo->lastInsertId();

echo 'Zamowienie testowe: ' . $orderId . ', kwota ' . number_format($kwotaZl, 2, ',', ' ') . ' zl' . PHP_EOL;

/**
 * Kasuje zamowienie testowe. Wolane takze przy bledzie.
 */
$sprzatanie = static function () use ($pdo, $prefix, $orderId): void {
    $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $orderId]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $orderId]);
};

register_shutdown_function($sprzatanie);

/**
 * Buduje poprawnie podpisane powiadomienie.
 */
$powiadomienie = static function (array $nadpisania = []) use ($config, $sessionId, $kwotaGrosze, $p24OrderId): string {
    $dane = array_merge([
        'merchantId'   => $config->merchantId,
        'posId'        => $config->posId,
        'sessionId'    => $sessionId,
        'amount'       => $kwotaGrosze,
        'originAmount' => $kwotaGrosze,
        'currency'     => 'PLN',
        'orderId'      => $p24OrderId,
        'methodId'     => 154,
        'statement'    => 'platnosc testowa',
    ], $nadpisania);

    if (!isset($nadpisania['sign'])) {
        $dane['sign'] = Signature::forNotification(
            $config->merchantId,
            $config->posId,
            $sessionId,
            (int) $dane['amount'],
            (int) $dane['originAmount'],
            (string) $dane['currency'],
            (int) $dane['orderId'],
            (int) $dane['methodId'],
            $dane['statement'],
            $config->crc
        );
    } else {
        $dane['sign'] = $nadpisania['sign'];
    }

    return json_encode($dane, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

/**
 * Uruchamia obsluge powiadomienia i zwraca status zamowienia po niej.
 *
 * @return array{wynik: mixed, status: string, wywolan: int}
 */
$uruchom = static function (string $tresc, string $tokenWUrl) use ($orderId, $pdo, $prefix): array {
    $atrapa = new TransportAtrapa();

    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaTestowa($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);

    $wtyczka->trescPowiadomienia = $tresc;
    $wtyczka->transport          = $atrapa;

    $wejscie = Joomla\CMS\Factory::getApplication()->input;
    $wejscie->set('order_id', $orderId);
    $wejscie->set('order_token', $tokenWUrl);

    $statusy = [];
    $wynik   = $wtyczka->onPaymentNotification($statusy);

    $q = $pdo->prepare("SELECT order_status FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    return [
        'wynik'   => $wynik,
        'status'  => (string) $q->fetchColumn(),
        'wywolan' => count($atrapa->wywolania),
    ];
};

$tokenPoprawny = md5($orderToken);

// --- testy -----------------------------------------------------------

echo PHP_EOL . '1. Zadania, ktore musza zostac odrzucone' . PHP_EOL;

$r = $uruchom($powiadomienie(), 'zupelnie-bledny-token');
wynik('bledny znacznik w adresie odrzucony', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);
wynik('weryfikacja w ogole nie zostala wykonana', $r['wywolan'] === 0);

$r = $uruchom($powiadomienie(['sign' => str_repeat('0', 96)]), $tokenPoprawny);
wynik('bledny podpis odrzucony', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);
wynik('nie pytamy P24 o transakcje z blednym podpisem', $r['wywolan'] === 0);

$r = $uruchom($powiadomienie(['amount' => 100, 'originAmount' => 100]), $tokenPoprawny);
wynik('zanizona kwota odrzucona', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);

$r = $uruchom($powiadomienie(['currency' => 'EUR']), $tokenPoprawny);
wynik('inna waluta odrzucona', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);

$r = $uruchom($powiadomienie(['merchantId' => 999999]), $tokenPoprawny);
wynik('obcy sprzedawca odrzucony', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);

$r = $uruchom('to nie jest JSON', $tokenPoprawny);
wynik('tresc, ktora nie jest JSON-em, odrzucona', $r['wynik'] === false);
wynik('status niezmieniony', $r['status'] === 'created', $r['status']);

echo PHP_EOL . '2. Poprawne powiadomienie' . PHP_EOL;

$r = $uruchom($powiadomienie(), $tokenPoprawny);
wynik('powiadomienie przyjete', $r['wynik'] === true);
wynik('weryfikacja zostala wykonana', $r['wywolan'] === 1);
wynik('status zmieniony na oplacony', $r['status'] === $config->verifiedStatus, $r['status']);

$q = $pdo->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
$q->execute([':id' => $orderId]);
$zapisane = (object) (array) @unserialize((string) $q->fetchColumn());

wynik('zapisano identyfikator transakcji P24', ($zapisane->{OrderPaymentData::P24_ORDER_ID} ?? 0) === $p24OrderId);
wynik('zapisano metode platnosci P24', ($zapisane->{OrderPaymentData::METHOD_ID} ?? 0) === 154);
wynik('zapisano chwile potwierdzenia', !empty($zapisane->{OrderPaymentData::VERIFIED_AT}));

echo PHP_EOL . '3. Powtorzone powiadomienie' . PHP_EOL;

$r = $uruchom($powiadomienie(), $tokenPoprawny);
wynik('powtorzenie przyjete bez bledu', $r['wynik'] === true);
wynik('P24 nie jest pytane drugi raz', $r['wywolan'] === 0);
wynik('status pozostal oplacony', $r['status'] === $config->verifiedStatus, $r['status']);

// HikaShop dopisuje wpis do historii przy kazdym zapisie zamowienia,
// takze przy samym odlozeniu danych transakcji. Liczy sie to, ile razy
// zamowienie zostalo przestawione na status oplacony: musi byc raz.
$q = $pdo->prepare("
    SELECT COUNT(*) FROM {$prefix}hikashop_history
    WHERE history_order_id = :id AND history_new_status = :status
");
$q->execute([':id' => $orderId, ':status' => $config->verifiedStatus]);
$zmianStatusu = (int) $q->fetchColumn();
wynik('status zostal przestawiony na oplacony dokladnie raz', $zmianStatusu === 1, $zmianStatusu . ' razy');

$q = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}hikashop_history WHERE history_order_id = :id");
$q->execute([':id' => $orderId]);
echo '       (wpisow w historii lacznie: ' . (int) $q->fetchColumn() . ')' . PHP_EOL;

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;
echo 'Zamowienie testowe ' . $orderId . ' zostanie skasowane.' . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
