<?php
/**
 * Testy zwrotow.
 *
 * Uruchomienie:
 *   php tests/refund.php
 *
 * Siec podstawiona atrapa transportu HTTP Joomli, wiec sprawdzamy
 * prawdziwa tresc zadania wyslanego do P24 i prawdziwa obsluge
 * odpowiedzi, bez ruszania czyichkolwiek pieniedzy.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Http\TransportInterface;
use Joomla\Uri\UriInterface;
use Laminas\Diactoros\Stream;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RefundService;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RefundStatus;

$zdane = 0;
$bledy = 0;

function wynik(string $opis, mixed $oczekiwane, mixed $otrzymane): void
{
    global $zdane, $bledy;

    if ($oczekiwane === $otrzymane) {
        $zdane++;
        echo '  OK   ' . $opis . PHP_EOL;

        return;
    }

    $bledy++;
    echo '  BLAD ' . $opis . PHP_EOL;
    echo '       oczekiwano: ' . var_export($oczekiwane, true) . PHP_EOL;
    echo '       otrzymano:  ' . var_export($otrzymane, true) . PHP_EOL;
}

final class TransportZwrotu implements TransportInterface
{
    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    public function __construct(
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"refunds":[]}}'
    ) {
    }

    public function request($method, UriInterface $uri, $data = null, array $headers = [], $timeout = null, $userAgent = null)
    {
        $this->wywolania[] = ['metoda' => $method, 'adres' => (string) $uri, 'tresc' => $data];

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
 * Sklada usluge zwrotow na podstawionym transporcie.
 */
function uslugaZwrotu(TransportZwrotu $transport, Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config $config): RefundService
{
    $logger = new Logger('przelewy24', false, $config->secrets(), static function (): void {});
    $client = new ApiClient($config, $logger, '1.0.0', 'https://haskap.test', new Http([], $transport));

    return new RefundService($client, $config, $logger);
}

$metoda = metodaPlatnosciP24();
$config = $metoda['config'];

echo 'Metoda platnosci: id=' . $metoda['id'] . ', srodowisko ' . $config->environment->value . PHP_EOL;

echo PHP_EOL . '1. Stan zwrotu' . PHP_EOL;
wynik('1 to zwrot zrealizowany', RefundStatus::Completed, RefundStatus::fromApi(1));
wynik('2 to zwrot w trakcie', RefundStatus::Pending, RefundStatus::fromApi(2));
wynik('3 to zwrot do potwierdzenia', RefundStatus::ToConfirm, RefundStatus::fromApi(3));
wynik('4 to zwrot odrzucony', RefundStatus::Rejected, RefundStatus::fromApi(4));
wynik('nieznana wartosc daje null', null, RefundStatus::fromApi(99));
wynik('tekst daje null', null, RefundStatus::fromApi('cokolwiek'));
wynik('tylko zrealizowany jest zakonczony', true, RefundStatus::Completed->isFinished());
wynik('w trakcie nie jest zakonczony', false, RefundStatus::Pending->isFinished());
wynik('do potwierdzenia liczy sie jako oczekujacy', true, RefundStatus::ToConfirm->isPending());
wynik('odrzucony nie jest oczekujacy', false, RefundStatus::Rejected->isPending());

echo PHP_EOL . '2. Tresc zadania wyslanego do P24' . PHP_EOL;

$transport = new TransportZwrotu(200, '{"data":{"refunds":[{"requestId":"x","status":1}]}}');
$usluga    = uslugaZwrotu($transport, $config);

$wynikZwrotu = $usluga->refund('hika_41_abc', 555111, 1150, 'Zwrot do zamowienia nr 41', 'https://haskap.test/refund-notify');

wynik('wyslano dokladnie jedno zadanie', 1, count($transport->wywolania));
wynik('metoda HTTP to POST', 'POST', $transport->wywolania[0]['metoda']);
wynik('adres to endpoint zwrotow', true, str_contains($transport->wywolania[0]['adres'], 'api/v1/transaction/refund'));
wynik('adres prowadzi do sandboxa', true, str_contains($transport->wywolania[0]['adres'], 'sandbox.przelewy24.pl'));

$tresc = json_decode((string) $transport->wywolania[0]['tresc'], true);

wynik('zadanie ma requestId', true, !empty($tresc['requestId']));
wynik('zadanie ma refundsUuid', true, !empty($tresc['refundsUuid']));
wynik('refundsUuid miesci sie w 36 znakach', true, strlen((string) $tresc['refundsUuid']) <= 36);
wynik('jest dokladnie jedna pozycja zwrotu', 1, count($tresc['refunds']));
wynik('identyfikator transakcji P24 przekazany', 555111, $tresc['refunds'][0]['orderId']);
wynik('identyfikator sesji przekazany', 'hika_41_abc', $tresc['refunds'][0]['sessionId']);
wynik('kwota jest liczba calkowita groszy', 1150, $tresc['refunds'][0]['amount']);
wynik('adres powiadomienia przekazany', 'https://haskap.test/refund-notify', $tresc['urlStatus'] ?? '');
wynik('odczytano stan zwrotu z odpowiedzi', RefundStatus::Completed, $wynikZwrotu['status']);
wynik('zwrocony requestId zgadza sie z wyslanym', $tresc['requestId'], $wynikZwrotu['requestId']);

echo PHP_EOL . '3. Czego zwrot musi odmowic' . PHP_EOL;

$rzucil = false;

try {
    uslugaZwrotu(new TransportZwrotu(), $config)->refund('hika_41_abc', 555111, 0, 'zero');
} catch (InvalidArgumentException) {
    $rzucil = true;
}

wynik('kwota zerowa konczy sie wyjatkiem', true, $rzucil);

$rzucil = false;

try {
    uslugaZwrotu(new TransportZwrotu(), $config)->refund('hika_41_abc', 555111, -100, 'ujemna');
} catch (InvalidArgumentException) {
    $rzucil = true;
}

wynik('kwota ujemna konczy sie wyjatkiem', true, $rzucil);

$transportBledu = new TransportZwrotu(400, '{"error":"Refund not possible","code":400}');
$rzucil         = false;
$komunikat      = '';

try {
    uslugaZwrotu($transportBledu, $config)->refund('hika_41_abc', 555111, 1150, 'test');
} catch (ApiException $e) {
    $rzucil    = true;
    $komunikat = $e->getMessage();
}

wynik('odmowa P24 konczy sie wyjatkiem', true, $rzucil);
wynik('wyjatek niesie komunikat od P24', true, str_contains($komunikat, 'Refund not possible'));

echo PHP_EOL . '4. Odczyt stanu zwrotow' . PHP_EOL;

$transportStanu = new TransportZwrotu(200, '{"data":{"refunds":[{"requestId":"a","status":1},{"requestId":"b","status":2}]}}');
$stany          = uslugaZwrotu($transportStanu, $config)->statusesFor(555111);

wynik('odczytano dwa zwroty', 2, count($stany));
wynik('pierwszy jest zrealizowany', RefundStatus::Completed, $stany['a'] ?? null);
wynik('drugi jest w trakcie', RefundStatus::Pending, $stany['b'] ?? null);

$stanyPuste = uslugaZwrotu(new TransportZwrotu(404, '{"error":"not found","code":404}'), $config)->statusesFor(999);
wynik('brak zwrotow nie wywraca odczytu', [], $stanyPuste);

echo PHP_EOL . '5. Wtyczka' . PHP_EOL;

$dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
$wtyczka    = hikashop_import('hikashoppayment', 'przelewy24');

wynik('wtyczka deklaruje obsluge zwrotow', true, !empty($wtyczka->features['refund']));
wynik('metoda onOrderPaymentRefund istnieje', true, method_exists($wtyczka, 'onOrderPaymentRefund'));

$puste = new stdClass();
wynik('zwrot bez zamowienia zwraca false', false, $wtyczka->onOrderPaymentRefund($puste, 10.0));

echo PHP_EOL . '6. Wyzwalacz zwrotu przez zmiane statusu' . PHP_EOL;

wynik('metoda onAfterOrderUpdate() istnieje', true, method_exists($wtyczka, 'onAfterOrderUpdate'));
wynik('domyslnie wyzwalacz jest wylaczony', '', Config::fromPaymentParams(null)->refundStatus);
wynik('pusty status czyta sie jako wylaczony', '', Config::fromPaymentParams((object) ['refund_status' => '   '])->refundStatus);
wynik('wskazany status jest odczytany', 'refunded', Config::fromPaymentParams((object) ['refund_status' => 'refunded'])->refundStatus);

// Zamowienie testowe: oplacone, metoda przelewy24, bez zgloszonego zwrotu.
$prefix = przedrostekTabel();
$pdo    = polaczenieZBaza();

$orderToken = bin2hex(random_bytes(16));
$pdo->prepare("
    INSERT INTO {$prefix}hikashop_order
        (order_number, order_created, order_modified, order_status, order_type,
         order_full_price, order_currency_id, order_payment_id, order_payment_method,
         order_token, order_payment_params)
    VALUES
        (:numer, :teraz, :teraz, :status, 'sale', 12.00, 125, :platnosc, 'przelewy24', :token, :parametry)
")->execute([
    ':numer'     => 'P24REF' . random_int(1000, 9999),
    ':teraz'     => time(),
    ':status'    => $config->verifiedStatus,
    ':platnosc'  => $metoda['id'],
    ':token'     => $orderToken,
    ':parametry' => serialize((object) [
        'p24_session_id' => 'hika_test_' . bin2hex(random_bytes(8)),
        'p24_order_id'   => 0,
        'p24_amount'     => 1200,
        'p24_currency'   => 'PLN',
    ]),
]);

$orderId = (int) $pdo->lastInsertId();

register_shutdown_function(static function () use ($pdo, $prefix, $orderId): void {
    $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $orderId]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $orderId]);
});

echo '       zamowienie testowe: ' . $orderId . PHP_EOL;

/**
 * Wola wyzwalacz i mowi, czy doszlo do zgloszenia zwrotu.
 */
function czyZgloszonoZwrot($wtyczka, int $orderId, string $status): bool
{
    $zdarzenie = (object) ['order_id' => $orderId, 'order_status' => $status];
    $mail      = false;

    $wtyczka->onAfterOrderUpdate($zdarzenie, $mail);

    $prefix = przedrostekTabel();
    $q      = polaczenieZBaza()->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    $par = (object) (array) @unserialize((string) $q->fetchColumn());

    return ($par->p24_refund_request_id ?? '') !== '';
}

// Wyzwalacz wylaczony: zmiana statusu nie moze niczego zwrocic.
wynik('przy wylaczonym wyzwalaczu zmiana statusu nic nie robi', false, czyZgloszonoZwrot($wtyczka, $orderId, 'refunded'));

// Wyzwalacz wlaczony, ale status inny niz ustawiony.
//
// UWAGA: zmieniamy tu prawdziwa konfiguracje metody platnosci w sklepie.
// Pierwotny stan zapamietujemy i przywracamy przy wyjsciu, TAKZE przy
// bledzie. Pozostawienie wlaczonego wyzwalacza oznaczaloby sklep, ktory
// oddaje klientom pieniadze przy zmianie statusu zamowienia.
$parametryPrzed = (string) $pdo->query(
    "SELECT payment_params FROM {$prefix}hikashop_payment WHERE payment_id = {$metoda['id']}"
)->fetchColumn();

register_shutdown_function(static function () use ($pdo, $prefix, $metoda, $parametryPrzed): void {
    $pdo->prepare("UPDATE {$prefix}hikashop_payment SET payment_params = :p WHERE payment_id = :id")
        ->execute([':p' => $parametryPrzed, ':id' => $metoda['id']]);
});

$zmienione = (array) (object) (array) @unserialize($parametryPrzed);
$zmienione['refund_status'] = 'refunded';

$pdo->prepare("UPDATE {$prefix}hikashop_payment SET payment_params = :p WHERE payment_id = :id")
    ->execute([':p' => serialize((object) $zmienione), ':id' => $metoda['id']]);

wynik('inny status nie uruchamia zwrotu', false, czyZgloszonoZwrot($wtyczka, $orderId, 'shipped'));

// Zamowienie bez potwierdzonej transakcji P24: zwrot nie ma czego zwrocic.
wynik('brak transakcji P24 blokuje zwrot', false, czyZgloszonoZwrot($wtyczka, $orderId, 'refunded'));

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
