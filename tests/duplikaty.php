<?php
/**
 * Sesje platnosci zamowienia i ochrona przed zaplaceniem dwa razy.
 *
 * Uruchomienie:
 *   php tests/duplikaty.php
 *
 * Test odzywa sie do prawdziwego sandboxa P24, ale nie wymaga adresu
 * osiagalnego z internetu: sprawdza wylacznie to, co sklep WYSYLA.
 *
 * Tlo: po nieudanej platnosci P24 nie pozwala dokonczyc tej samej
 * transakcji, wiec kazda proba dostaje wlasna sesje. Wczesniejsze sesje
 * zostaja przy zamowieniu: przed kolejna proba sklep pyta P24 o kazda
 * z nich, a powiadomienie o wplacie na dowolna z nich nalezy do zamowienia.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\TransactionService;

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

$metoda = metodaPlatnosciP24();
$config = $metoda['config'];

echo 'Srodowisko: ' . $config->environment->value . PHP_EOL;

echo PHP_EOL . '1. Kazda proba ma wlasna sesje, wczesniejsze zostaja przy zamowieniu' . PHP_EOL;

$pierwsza = SessionId::generate(4242);
$druga    = SessionId::generate(4242);
$trzecia  = SessionId::generate(4242);

wynik('dwie proby tego samego zamowienia dostaja rozne sesje', false, $pierwsza === $druga);
wynik('rozne zamowienia dostaja rozne sesje', false, SessionId::generate(1) === SessionId::generate(2));
wynik('zamowienie bez prob nie ma sesji', [], OrderPaymentData::sessionsOf(null));

// Po pierwszej probie przy zamowieniu jest jedna sesja.
$poPierwszej = (object) ['order_payment_params' => (object) [OrderPaymentData::SESSION_ID => $pierwsza]];
wynik('jedna proba: jedna sesja', [$pierwsza], OrderPaymentData::sessionsOf($poPierwszej));
wynik('przy drugiej probie pierwsza sesja przechodzi na liste wczesniejszych', [$pierwsza], OrderPaymentData::earlierSessions($poPierwszej, $druga));

// Po drugiej probie: biezaca druga, na liscie pierwsza.
$poDrugiej = (object) ['order_payment_params' => (object) [
    OrderPaymentData::SESSION_ID => $druga,
    OrderPaymentData::SESSIONS   => [$pierwsza],
]];
wynik('sesje zamowienia sa podawane od najnowszej', [$druga, $pierwsza], OrderPaymentData::sessionsOf($poDrugiej));
wynik('przy trzeciej probie lista zachowuje kolejnosc prob', [$pierwsza, $druga], OrderPaymentData::earlierSessions($poDrugiej, $trzecia));
wynik('ta sama sesja nie trafia na liste drugi raz', [$pierwsza], OrderPaymentData::earlierSessions($poDrugiej, $druga));

// HikaShop oddaje to pole raz jako obiekt, raz jako ciag po serialize.
$poSerializacji = (object) ['order_payment_params' => serialize($poDrugiej->order_payment_params)];
wynik('lista sesji przezywa zapis do bazy', [$druga, $pierwsza], OrderPaymentData::sessionsOf($poSerializacji));

// Uszkodzona wartosc nie moze zablokowac platnosci ani trafic do P24.
$zeSmieciem = (object) ['order_payment_params' => (object) [
    OrderPaymentData::SESSION_ID => 'nie jest poprawny!',
    OrderPaymentData::SESSIONS   => ['tez smiec!', $pierwsza, ['tablica']],
]];
wynik('niepoprawne zapisy sa pomijane', [$pierwsza], OrderPaymentData::sessionsOf($zeSmieciem));
wynik('lista, ktora nie jest lista, nie wysypuje odczytu', [$druga], OrderPaymentData::sessionsOf((object) ['order_payment_params' => (object) [
    OrderPaymentData::SESSION_ID => $druga,
    OrderPaymentData::SESSIONS   => 'sieczka',
]]));

// Lista nie rosnie bez konca: odpadaja najstarsze sesje.
$duzo = [];

for ($i = 0; $i < OrderPaymentData::SESSIONS_LIMIT + 5; $i++) {
    $duzo[] = SessionId::generate(4242);
}

$poWielu = (object) ['order_payment_params' => (object) [
    OrderPaymentData::SESSION_ID => $druga,
    OrderPaymentData::SESSIONS   => $duzo,
]];
$przyciete = OrderPaymentData::earlierSessions($poWielu, $trzecia);

wynik('lista wczesniejszych sesji ma limit', OrderPaymentData::SESSIONS_LIMIT, count($przyciete));
wynik('na liscie zostaja najnowsze sesje', $druga, end($przyciete));
wynik('z listy odpada najstarsza sesja', false, in_array($duzo[0], $przyciete, true));

echo PHP_EOL . '2. Prawdziwe P24: ta sama sesja to ta sama transakcja, nowa sesja to nowa' . PHP_EOL;

$logger = new Logger('przelewy24', false, $config->secrets(), static function (): void {});
$client = new ApiClient($config, $logger, '1.0.0', 'https://haskap.test');
$usluga = new TransactionService($client, $config, $logger);

$sessionId = SessionId::generate(999123);
$kwota     = Amount::toMinorUnit(23.45);

$zadanie = new RegisterRequest(
    sessionId: $sessionId,
    amountInMinorUnits: $kwota,
    currency: 'PLN',
    description: 'Test dublowania platnosci',
    email: 'p24-test@example.invalid',
    urlReturn: 'https://haskap.test/return',
    urlStatus: 'https://haskap.test/notify',
    client: 'P24 TEST'
);

$token1 = $usluga->register($zadanie);
$token2 = $usluga->register($zadanie);
$token3 = $usluga->register($zadanie);

wynik('pierwsza rejestracja daje token', true, is_string($token1) && $token1 !== '');
wynik('druga rejestracja zwraca TEN SAM token', $token1, $token2);
wynik('trzecia rejestracja tez ten sam', $token1, $token3);

echo '       token: ' . $token1 . PHP_EOL;

// Ten sam token oznacza te sama transakcje, a tej po nieudanej platnosci
// nie da sie juz dokonczyc: jej strona od razu odsyla klienta do sklepu.
// Dlatego ponowienie rejestruje nowa sesje, czyli nowa transakcje.
$inneZadanie = new RegisterRequest(
    sessionId: SessionId::generate(999123),
    amountInMinorUnits: $kwota,
    currency: 'PLN',
    description: 'Test dublowania platnosci',
    email: 'p24-test@example.invalid',
    urlReturn: 'https://haskap.test/return',
    urlStatus: 'https://haskap.test/notify',
    client: 'P24 TEST'
);

$tokenInny = $usluga->register($inneZadanie);

wynik('nowa sesja daje INNY token, czyli nowa transakcje do oplacenia', false, $tokenInny === $token1);

echo PHP_EOL . '3. Blokada ponownej zaplaty za oplacone zamowienie' . PHP_EOL;

$wtyczka = hikashop_import('hikashoppayment', 'przelewy24');

$refleksja = new ReflectionMethod($wtyczka, 'isAlreadyPaid');
$refleksja->setAccessible(true);

$oplacone   = (object) ['order_status' => $config->verifiedStatus];
$nieoplacone = (object) ['order_status' => 'created'];
$bezStatusu = (object) [];

wynik('zamowienie w statusie oplaconego jest rozpoznane', true, $refleksja->invoke($wtyczka, $oplacone, $config));
wynik('zamowienie oczekujace nie jest uznane za oplacone', false, $refleksja->invoke($wtyczka, $nieoplacone, $config));
wynik('brak statusu nie jest uznany za oplacone', false, $refleksja->invoke($wtyczka, $bezStatusu, $config));

// Do 1.0.6 za oplacone uchodzilo tylko zamowienie w statusie potwierdzenia.
// Zamowienie wyslane tez jest oplacone, a najpewniejszym dowodem jest nasz
// wlasny znacznik weryfikacji, niezalezny od tego, jak nazywa sie status.
$wyslane      = (object) ['order_status' => 'shipped'];
$anulowane    = (object) ['order_status' => 'cancelled'];
$zeZnacznikiem = (object) [
    'order_status'         => 'created',
    'order_payment_params' => (object) [OrderPaymentData::VERIFIED_AT => gmdate('c')],
];
$zPustymZnacznikiem = (object) [
    'order_status'         => 'created',
    'order_payment_params' => (object) [OrderPaymentData::VERIFIED_AT => ''],
];

wynik('zamowienie wyslane jest uznane za oplacone', true, $refleksja->invoke($wtyczka, $wyslane, $config));
wynik('zamowienie anulowane nie jest uznane za oplacone', false, $refleksja->invoke($wtyczka, $anulowane, $config));
wynik('znacznik weryfikacji wystarcza, niezaleznie od statusu', true, $refleksja->invoke($wtyczka, $zeZnacznikiem, $config));
wynik('pusty znacznik niczego nie dowodzi', false, $refleksja->invoke($wtyczka, $zPustymZnacznikiem, $config));
wynik('brak zamowienia nie wywraca sprawdzenia', false, $refleksja->invoke($wtyczka, null, $config));

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
