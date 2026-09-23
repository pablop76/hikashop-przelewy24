<?php
/**
 * Czy ponowienie zaplaty tworzy w P24 druga transakcje?
 *
 * Uruchomienie:
 *   php tests/duplikaty.php
 *
 * Test odzywa sie do prawdziwego sandboxa P24, ale nie wymaga adresu
 * osiagalnego z internetu: sprawdza wylacznie to, co sklep WYSYLA.
 *
 * Tlo: klient, ktory zaplacil, ale nie doczekal sie powiadomienia, wraca
 * do kasy i placi ponownie. Jesli kazda proba dostaje nowy identyfikator
 * sesji, P24 zaklada druga transakcje i klient placi dwa razy.
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

echo PHP_EOL . '1. Identyfikator sesji jest staly dla zamowienia' . PHP_EOL;

// Zamowienie bez zapisanej sesji: identyfikator powstaje.
$pierwsza = OrderPaymentData::sessionIdFor(null, 4242);
wynik('przy pierwszej probie powstaje identyfikator', true, SessionId::isValid($pierwsza));

// Zamowienie z zapisana sesja: identyfikator zostaje ten sam.
$zZapisana = (object) [
    'order_payment_params' => (object) [OrderPaymentData::SESSION_ID => $pierwsza],
];
wynik('przy ponowieniu identyfikator sie NIE zmienia', $pierwsza, OrderPaymentData::sessionIdFor($zZapisana, 4242));

// Uszkodzona wartosc nie moze zablokowac platnosci.
$zeSmieciem = (object) ['order_payment_params' => (object) [OrderPaymentData::SESSION_ID => 'nie jest poprawny!']];
$poSmieciu  = OrderPaymentData::sessionIdFor($zeSmieciem, 4242);
wynik('niepoprawny zapis jest zastepowany nowym', true, SessionId::isValid($poSmieciu));
wynik('i nie jest to ta sama wartosc co smiec', false, $poSmieciu === 'nie jest poprawny!');

// Rozne zamowienia nie moga dzielic identyfikatora.
wynik('rozne zamowienia dostaja rozne identyfikatory', false, OrderPaymentData::sessionIdFor(null, 1) === OrderPaymentData::sessionIdFor(null, 2));

echo PHP_EOL . '2. Prawdziwe P24: ponowienie nie tworzy drugiej transakcji' . PHP_EOL;

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

// Dla kontrastu: inny identyfikator sesji to juz inna transakcja,
// czyli dokladnie to, czego chcemy uniknac przy ponowieniu.
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

wynik('nowy identyfikator sesji daje INNY token, czyli druga transakcje', false, $tokenInny === $token1);

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

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
