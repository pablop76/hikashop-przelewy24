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

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Http\TransportInterface;
use Joomla\Uri\UriInterface;
use Laminas\Diactoros\Stream;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Signature;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\TransactionService;

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
final class WtyczkaTestowa extends Przelewy24
{
    public string $trescPowiadomienia = '';

    public ?TransportAtrapa $transport = null;

    /** @var list<mixed> wiadomosci, ktore wtyczka chciala wyslac sprzedawcy */
    public array $alerty = [];

    /** @var list<string> statusy, na ktore wtyczka przestawiala zamowienie */
    public array $zmianyStatusu = [];

    /**
     * Falsz w miejscu numeru zamowienia to prosba o sama wiadomosc do
     * sprzedawcy, bez zapisu zamowienia. W tescie jej nie wysylamy, tylko
     * odkladamy, zeby dalo sie policzyc, ile ich bylo i co zawieraly.
     */
    public function modifyOrder(&$order_id, $order_status, $history = null, $email = null, $payment_params = null)
    {
        if ($order_id === false) {
            $this->alerty[] = $email;

            return;
        }

        $this->zmianyStatusu[] = (string) $order_status;

        parent::modifyOrder($order_id, $order_status, $history, $email, $payment_params);
    }

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

$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();

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
$numerZamowienia = 'P24TEST' . random_int(1000, 9999);

/**
 * Dane transakcji zapisane przy zamowieniu przed zaplata.
 */
$parametryPrzedZaplata = [
    OrderPaymentData::SESSION_ID => $sessionId,
    OrderPaymentData::AMOUNT     => $kwotaGrosze,
    OrderPaymentData::CURRENCY   => 'PLN',
    OrderPaymentData::ATTEMPTS   => 1,
];

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
    ':numer'     => $numerZamowienia,
    ':utworzone' => time(),
    ':cena'      => $kwotaZl,
    ':platnosc'  => $metoda['payment_id'],
    ':token'     => $orderToken,
    ':parametry' => serialize((object) $parametryPrzedZaplata),
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
 * @return array{wynik: mixed, status: string, wywolan: int, alertow: int, alert: mixed, zmian: list<string>}
 */
$uruchom = static function (string $tresc, string $tokenWUrl, ?TransportAtrapa $wlasnaAtrapa = null) use ($orderId, $pdo, $prefix): array {
    $atrapa = $wlasnaAtrapa ?? new TransportAtrapa();

    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaTestowa($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);

    $wtyczka->trescPowiadomienia = $tresc;
    $wtyczka->transport          = $atrapa;

    $wejscie = Joomla\CMS\Factory::getApplication()->getInput();
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
        'alertow' => count($wtyczka->alerty),
        'alert'   => $wtyczka->alerty[0] ?? null,
        'zmian'   => $wtyczka->zmianyStatusu,
    ];
};

/**
 * Parametry platnosci zapisane teraz przy zamowieniu.
 */
$zapisaneParametry = static function () use ($pdo, $prefix, $orderId): object {
    $q = $pdo->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    return (object) (array) @unserialize((string) $q->fetchColumn());
};

/**
 * Przywraca zamowienie do stanu sprzed zaplaty: status oraz dane
 * transakcji, czyli takze znaczniki weryfikacji i wyslanej wiadomosci.
 *
 * @param  array<string, mixed>  $dodatkowe  pola dopisane do danych transakcji
 */
$zresetuj = static function (string $status = 'created', array $dodatkowe = []) use ($pdo, $prefix, $orderId, $parametryPrzedZaplata): void {
    $pdo->prepare("UPDATE {$prefix}hikashop_order SET order_status = :status, order_payment_params = :parametry WHERE order_id = :id")
        ->execute([
            ':status'    => $status,
            ':parametry' => serialize((object) ($dodatkowe + $parametryPrzedZaplata)),
            ':id'        => $orderId,
        ]);
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

echo PHP_EOL . '4. Blad weryfikacji nie zmienia statusu zamowienia' . PHP_EOL;

// P24 wysyla powiadomienia tylko dla transakcji oplaconych. Blad weryfikacji
// po poprawnie podpisanym powiadomieniu to wiec klopot po naszej stronie albo
// po stronie P24 (klucz, adres IP, awaria), a klient zaplacil. Do 1.0.6 taka
// odpowiedz nadawala zamowieniu status nieudanej platnosci.

// Zamowienie jest zweryfikowane po sekcji 2: kolejne powiadomienie niczego
// nie rusza, niezaleznie od tego, co odpowiedzialoby P24.
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(400, '{"error":"Error call 2","code":400}'));
wynik('zweryfikowanego zamowienia nie ruszamy', $r['status'] === $config->verifiedStatus, $r['status']);
wynik('i w ogole nie pytamy o nie P24', $r['wywolan'] === 0);

$zresetuj();
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(400, '{"error":"Error call 2","code":400}'));
wynik('odpowiedz HTTP 400 NIE zmienia statusu', $r['status'] === 'created', $r['status']);
wynik('weryfikacja zostala wykonana', $r['wywolan'] === 1);
wynik('obsluga konczy sie niepowodzeniem', $r['wynik'] === false);
wynik('sprzedawca dostaje jedna wiadomosc', $r['alertow'] === 1, $r['alertow'] . ' wiadomosci');

$temat = is_object($r['alert']) ? (string) ($r['alert']->subject ?? '') : '';
$tresc = is_object($r['alert']) ? (string) ($r['alert']->body ?? '') : '';

wynik('temat wymienia numer zamowienia', str_contains($temat, $numerZamowienia), $temat);
wynik('tresc podaje odpowiedz P24', str_contains($tresc, 'HTTP 400') && str_contains($tresc, 'Error call 2'));
wynik('tresc podaje identyfikator sesji do odszukania w panelu P24', str_contains($tresc, $sessionId));
wynik('w wiadomosci nie ma klucza CRC ani klucza API', !str_contains($tresc, $config->crc) && !str_contains($tresc, $config->apiKey));
wynik('tlumaczenia wiadomosci sa wczytane', !str_contains($temat . $tresc, 'PLG_HIKASHOPPAYMENT'), $temat);
wynik('przy zamowieniu zapisano, ze wiadomosc poszla', !empty($zapisaneParametry()->{OrderPaymentData::VERIFY_ALERT_AT}));
wynik('nieudana weryfikacja nie udaje potwierdzonej', empty($zapisaneParametry()->{OrderPaymentData::VERIFIED_AT}));

// P24 ponawia powiadomienie przez kilka godzin. Kazde powtorzenie probuje
// weryfikacji od nowa, ale sprzedawca nie dostaje kolejnych wiadomosci.
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(400, '{"error":"Error call 2","code":400}'));
wynik('ponowione powiadomienie znow probuje weryfikacji', $r['wywolan'] === 1);
wynik('ale nie wysyla drugiej wiadomosci', $r['alertow'] === 0, $r['alertow'] . ' wiadomosci');
wynik('status nadal niezmieniony', $r['status'] === 'created', $r['status']);

// Gdy przyczyna zniknie, to samo zamowienie potwierdza sie normalnie.
$r = $uruchom($powiadomienie(), $tokenPoprawny);
wynik('po usunieciu przyczyny kolejne powiadomienie potwierdza zaplate', $r['status'] === $config->verifiedStatus, $r['status']);

$zresetuj();
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(401, '{"error":"Incorrect authentication","code":401}'));
wynik('odrzucone dane dostepowe (401) NIE zmieniaja statusu', $r['status'] === 'created', $r['status']);
wynik('sprzedawca dostaje wiadomosc', $r['alertow'] === 1);

$zresetuj();
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(500, '{"error":"Internal Server Error","code":500}'));
wynik('awaria P24 (500) NIE zmienia statusu', $r['status'] === 'created', $r['status']);

// Zerwane polaczenie albo sieczka zamiast odpowiedzi to takze nie odmowa.
$zresetuj();
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(200, 'to nie jest JSON'));
wynik('niepoprawna odpowiedz NIE zmienia statusu', $r['status'] === 'created', $r['status']);

// Jedyna jawna odmowa: P24 odpowiada poprawnie, ale statusem innym niz success.
$zresetuj();
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(200, '{"data":{"status":"rejected"}}'));
wynik('odpowiedz inna niz success nadaje status nieudanej platnosci', $r['status'] === $config->invalidStatus, $r['status']);
wynik('jawna odmowa nie jest powodem do wiadomosci o awarii', $r['alertow'] === 0);

echo PHP_EOL . '5. Zamowienie, ktore ma juz status oplaconego' . PHP_EOL;

// Sprzedawca potrafi potwierdzic zamowienie recznie, zanim dojdzie
// powiadomienie. P24 rozlicza wplate dopiero po transaction/verify, wiec
// weryfikacje trzeba wykonac mimo statusu. Do 1.0.6 byla pomijana.
$zresetuj($config->verifiedStatus);
$r = $uruchom($powiadomienie(), $tokenPoprawny);
wynik('zaplata jest weryfikowana mimo statusu oplaconego', $r['wywolan'] === 1);
wynik('status zostaje, jaki byl', $r['status'] === $config->verifiedStatus, $r['status']);
wynik('wtyczka nie przestawia statusu drugi raz', $r['zmian'] === [], implode(',', $r['zmian']));
wynik('zapisano chwile potwierdzenia', !empty($zapisaneParametry()->{OrderPaymentData::VERIFIED_AT}));
wynik('powiadomienie przyjete', $r['wynik'] === true);

// Zamowienie poszlo juz do wysylki. Cofniecie go do statusu potwierdzenia
// wyslaloby klientowi drugi e-mail i mieszalo w historii.
$zresetuj('shipped');
$r = $uruchom($powiadomienie(), $tokenPoprawny);
wynik('zamowienie wyslane: weryfikacja wykonana', $r['wywolan'] === 1);
wynik('zamowienie wyslane nie cofa sie do potwierdzonego', $r['status'] === 'shipped', $r['status']);
wynik('wtyczka nie zmienia jego statusu', $r['zmian'] === [], implode(',', $r['zmian']));

// Jawna odmowa P24 tez nie moze cofnac zamowienia wyslanego.
$zresetuj('shipped');
$r = $uruchom($powiadomienie(), $tokenPoprawny, new TransportAtrapa(200, '{"data":{"status":"rejected"}}'));
wynik('zamowienia wyslanego nie przestawiamy na nieudana platnosc', $r['status'] === 'shipped', $r['status']);

echo PHP_EOL . '6. Druga transakcja dla zweryfikowanego zamowienia' . PHP_EOL;

// Gdyby klient zaplacil za to samo zamowienie drugi raz, drugiej wplaty
// nie weryfikujemy: niezweryfikowana zostaje w P24 do dyspozycji klienta.
$zresetuj($config->verifiedStatus, [
    OrderPaymentData::P24_ORDER_ID => $p24OrderId,
    OrderPaymentData::VERIFIED_AT  => gmdate('c'),
]);
$r = $uruchom($powiadomienie(['orderId' => $p24OrderId + 1]), $tokenPoprawny);
wynik('druga transakcja nie jest weryfikowana', $r['wywolan'] === 0);
wynik('status bez zmian', $r['status'] === $config->verifiedStatus, $r['status']);
wynik('przy zamowieniu zostaje pierwsza transakcja', (int) ($zapisaneParametry()->{OrderPaymentData::P24_ORDER_ID} ?? 0) === $p24OrderId);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;
echo 'Zamowienie testowe ' . $orderId . ' zostanie skasowane.' . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
