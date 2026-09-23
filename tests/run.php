<?php
/**
 * Testy jednostkowe warstwy P24 niezależnej od Joomli.
 *
 * Uruchomienie: php tests/run.php
 *
 * Celowo bez PHPUnita i bez composera: te klasy nie mają zależności,
 * a wtyczka ma się instalować bez katalogu vendor.
 */

define('_JEXEC', 1);

require __DIR__ . '/../src/lib/Autoloader.php';

use WebService\Przelewy24\Amount;
use WebService\Przelewy24\Autoloader;
use WebService\Przelewy24\Config;
use WebService\Przelewy24\Endpoints;
use WebService\Przelewy24\Environment;
use WebService\Przelewy24\Logger;
use WebService\Przelewy24\Notification;
use WebService\Przelewy24\OrderPaymentData;
use WebService\Przelewy24\RegisterRequest;
use WebService\Przelewy24\SessionId;
use WebService\Przelewy24\Signature;

Autoloader::register(__DIR__ . '/../src/lib');

$passed = 0;
$failed = 0;

function sprawdz(string $opis, mixed $oczekiwane, mixed $otrzymane): void
{
    global $passed, $failed;

    if ($oczekiwane === $otrzymane) {
        $passed++;
        echo '  OK   ' . $opis . PHP_EOL;

        return;
    }

    $failed++;
    echo '  BLAD ' . $opis . PHP_EOL;
    echo '       oczekiwano: ' . var_export($oczekiwane, true) . PHP_EOL;
    echo '       otrzymano:  ' . var_export($otrzymane, true) . PHP_EOL;
}

function sekcja(string $nazwa): void
{
    echo PHP_EOL . $nazwa . PHP_EOL;
}

sekcja('Amount: kwoty, na ktorych wyklada sie round(x, 2) * 100');
sprawdz('10,00 zl daje 1000 gr', 1000, Amount::toMinorUnit(10.00));
sprawdz('1,15 zl daje 115 gr', 115, Amount::toMinorUnit(1.15));
sprawdz('0,29 zl daje 29 gr', 29, Amount::toMinorUnit(0.29));
sprawdz('0,07 zl daje 7 gr', 7, Amount::toMinorUnit(0.07));
sprawdz('179,99 zl daje 17999 gr', 17999, Amount::toMinorUnit(179.99));
sprawdz('kwota jako tekst tez dziala', 3990, Amount::toMinorUnit('39.90'));
sprawdz('wynik jest typu int', 'integer', gettype(Amount::toMinorUnit(1.15)));
sprawdz('powrot na zlote', 1.15, Amount::fromMinorUnit(115));

$wszystkieCalkowite = true;
for ($grosze = 0; $grosze <= 100000; $grosze++) {
    if (Amount::toMinorUnit($grosze / 100) !== $grosze) {
        $wszystkieCalkowite = false;
        break;
    }
}
sprawdz('wszystkie kwoty 0-1000 zl przeliczaja sie bez straty', true, $wszystkieCalkowite);

sekcja('Signature: kolejnosc kluczy jest czescia specyfikacji');
$crc = 'testowy_crc_1234';
sprawdz(
    'podpis rejestracji: sessionId, merchantId, amount, currency, crc',
    hash('sha384', '{"sessionId":"abc","merchantId":12345,"amount":1000,"currency":"PLN","crc":"' . $crc . '"}'),
    Signature::forRegister('abc', 12345, 1000, 'PLN', $crc)
);
sprawdz(
    'podpis weryfikacji: sessionId, orderId, amount, currency, crc',
    hash('sha384', '{"sessionId":"abc","orderId":98765,"amount":1000,"currency":"PLN","crc":"' . $crc . '"}'),
    Signature::forVerify('abc', 98765, 1000, 'PLN', $crc)
);
sprawdz(
    'podpis powiadomienia obejmuje komplet 10 pol',
    hash('sha384', '{"merchantId":12345,"posId":12345,"sessionId":"abc","amount":1000,"originAmount":1000,"currency":"PLN","orderId":98765,"methodId":154,"statement":"opis","crc":"' . $crc . '"}'),
    Signature::forNotification(12345, 12345, 'abc', 1000, 1000, 'PLN', 98765, 154, 'opis', $crc)
);
sprawdz('podpis jest powtarzalny', Signature::forRegister('abc', 1, 1, 'PLN', $crc), Signature::forRegister('abc', 1, 1, 'PLN', $crc));
sprawdz('inna kwota daje inny podpis', false, Signature::forRegister('abc', 1, 1, 'PLN', $crc) === Signature::forRegister('abc', 1, 2, 'PLN', $crc));
sprawdz('pusty podpis nigdy nie pasuje', false, Signature::matches(str_repeat('a', 96), ''));
sprawdz('zgodny podpis pasuje', true, Signature::matches(str_repeat('a', 96), str_repeat('a', 96)));

sekcja('Environment: sandbox jest bezpiecznym domyslnym wyborem');
sprawdz('test_mode 1 daje sandbox', Environment::Sandbox, Environment::fromConfigValue('1'));
sprawdz('test_mode 0 daje produkcje', Environment::Production, Environment::fromConfigValue('0'));
sprawdz('brak wartosci daje sandbox', Environment::Sandbox, Environment::fromConfigValue(null));
sprawdz('smiec daje sandbox', Environment::Sandbox, Environment::fromConfigValue('tak'));
sprawdz('adres sandboxa', 'https://sandbox.przelewy24.pl/', Environment::Sandbox->baseUrl());
sprawdz('adres produkcji', 'https://secure.przelewy24.pl/', Environment::Production->baseUrl());

sekcja('SessionId: kazda proba zaplaty dostaje wlasny identyfikator');
$pierwszy = SessionId::generate(41);
$drugi    = SessionId::generate(41);
sprawdz('dwie proby dla tego samego zamowienia roznia sie', false, $pierwszy === $drugi);
sprawdz('identyfikator jest poprawny', true, SessionId::isValid($pierwszy));
sprawdz('miesci sie w limicie API', true, strlen($pierwszy) <= SessionId::MAX_LENGTH);
sprawdz('zawiera numer zamowienia dla latwiejszego szukania w panelu', true, str_contains($pierwszy, '_41_'));
sprawdz('spacja nie jest poprawnym identyfikatorem', false, SessionId::isValid('a b'));

sekcja('Endpoints: podstawianie parametrow');
sprawdz('sessionId trafia do adresu', 'api/v1/transaction/by/sessionId/abc123', Endpoints::build(Endpoints::TRANSACTION_BY_SESSION_ID, ['sessionId' => 'abc123']));
sprawdz('wartosc jest kodowana', 'api/v1/payment/methods/pl%2Fx', Endpoints::build(Endpoints::PAYMENT_METHODS, ['lang' => 'pl/x']));

$rzucil = false;

try {
    Endpoints::build(Endpoints::TRANSACTION_BY_SESSION_ID, []);
} catch (InvalidArgumentException) {
    $rzucil = true;
}

sprawdz('brak parametru konczy sie wyjatkiem, nie adresem ze znacznikiem', true, $rzucil);

sekcja('Config: komplet danych i uwierzytelnianie');
$params = (object) [
    'merchant_id'     => '12345',
    'crc_key'         => 'sekretny_crc_abc',
    'api_key'         => 'sekretny_klucz_api',
    'test_mode'       => '1',
    'debug'           => '1',
    'verified_status' => 'confirmed',
];
$config = Config::fromPaymentParams($params);
sprawdz('posId przyjmuje wartosc merchantId, gdy pole puste', 12345, $config->posId);
sprawdz('konfiguracja jest kompletna', true, $config->isComplete());
sprawdz('srodowisko to sandbox', Environment::Sandbox, $config->environment);
sprawdz('login to merchantId, nie posId', 'Basic ' . base64_encode('12345:sekretny_klucz_api'), $config->basicAuthHeader());
sprawdz('domyslny status bledu', 'cancelled', $config->invalidStatus);

$pusty = Config::fromPaymentParams(null);
sprawdz('brak parametrow to konfiguracja niekompletna', false, $pusty->isComplete());
sprawdz('pusta konfiguracja domyslnie celuje w sandbox', Environment::Sandbox, $pusty->environment);

$komunikat = '';

try {
    $pusty->assertComplete();
} catch (Throwable $e) {
    $komunikat = $e->getMessage();
}

sprawdz('wyjatek wymienia brakujace pola', true, str_contains($komunikat, 'merchant_id') && str_contains($komunikat, 'crc_key'));

sekcja('Logger: sekrety nie moga trafic do logu');
$zapisane = [];
$logger = new Logger('przelewy24', true, $config->secrets(), function (string $linia) use (&$zapisane): void {
    $zapisane[] = $linia;
});
$logger->error('Blad testowy', ['crc' => 'sekretny_crc_abc', 'order_id' => 41]);
sprawdz('wartosc pod kluczem crc jest wymazana', false, str_contains($zapisane[0], 'sekretny_crc_abc'));
sprawdz('numer zamowienia zostaje', true, str_contains($zapisane[0], 'order_id=41'));

$logger->error('Klucz w tresci: sekretny_klucz_api');
sprawdz('sekret wklejony w tresc tez jest wymazany', false, str_contains($zapisane[1], 'sekretny_klucz_api'));

$cichy = new Logger('przelewy24', false, [], function (string $linia) use (&$zapisane): void {
    $zapisane[] = $linia;
});
$przed = count($zapisane);
$cichy->info('Szczegol diagnostyczny');
sprawdz('info nie trafia do logu przy wylaczonej diagnostyce', $przed, count($zapisane));
$cichy->error('Blad');
sprawdz('blad trafia do logu zawsze', $przed + 1, count($zapisane));

sekcja('Notification: co musi odrzucic powiadomienie');

/**
 * Buduje poprawne powiadomienie dla zadanych danych.
 *
 * @return array<string, mixed>
 */
function powiadomienie(Config $config, string $sessionId, int $kwota, string $waluta, int $p24Order = 98765): array
{
    $dane = [
        'merchantId'   => $config->merchantId,
        'posId'        => $config->posId,
        'sessionId'    => $sessionId,
        'amount'       => $kwota,
        'originAmount' => $kwota,
        'currency'     => $waluta,
        'orderId'      => $p24Order,
        'methodId'     => 154,
        'statement'    => 'opis platnosci',
    ];

    $dane['sign'] = Signature::forNotification(
        $config->merchantId,
        $config->posId,
        $sessionId,
        $kwota,
        $kwota,
        $waluta,
        $p24Order,
        154,
        'opis platnosci',
        $config->crc
    );

    return $dane;
}

/**
 * Zwraca komunikat odrzucenia albo pusty ciag, gdy powiadomienie przeszlo.
 *
 * @param  array<string, mixed>  $dane
 */
function odrzucenie(array $dane, Config $config, string $zapisanaSesja, int $kwota, string $waluta): string
{
    try {
        Notification::fromArray($dane)->assertValid($config, $zapisanaSesja, $kwota, $waluta);

        return '';
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$sesja = 'hika_41_abcdef0123456789';
$poprawne = powiadomienie($config, $sesja, 1000, 'PLN');

sprawdz('poprawne powiadomienie przechodzi', '', odrzucenie($poprawne, $config, $sesja, 1000, 'PLN'));

$zlyPodpis = $poprawne;
$zlyPodpis['sign'] = str_repeat('0', 96);
sprawdz('zly podpis jest odrzucany', true, str_contains(odrzucenie($zlyPodpis, $config, $sesja, 1000, 'PLN'), 'Podpis'));

$brakPodpisu = $poprawne;
unset($brakPodpisu['sign']);
sprawdz('brak podpisu jest odrzucany', true, str_contains(odrzucenie($brakPodpisu, $config, $sesja, 1000, 'PLN'), 'Podpis'));

sprawdz(
    'podstawiona sesja jest odrzucana',
    true,
    str_contains(odrzucenie($poprawne, $config, 'hika_41_zupelnie_inna_sesja', 1000, 'PLN'), 'innej sesji')
);

$obcySprzedawca = powiadomienie($config, $sesja, 1000, 'PLN');
$obcySprzedawca['merchantId'] = 999;
sprawdz(
    'powiadomienie od innego sprzedawcy jest odrzucane',
    true,
    str_contains(odrzucenie($obcySprzedawca, $config, $sesja, 1000, 'PLN'), 'sprzedawc')
);

// Klient zaplacil 10 zl, zamowienie opiewa na 20 zl.
sprawdz(
    'zanizona kwota jest odrzucana',
    true,
    str_contains(odrzucenie($poprawne, $config, $sesja, 2000, 'PLN'), 'Kwota')
);

$innaWaluta = powiadomienie($config, $sesja, 1000, 'EUR');
sprawdz(
    'inna waluta jest odrzucana',
    true,
    str_contains(odrzucenie($innaWaluta, $config, $sesja, 1000, 'PLN'), 'Waluta')
);

$rzucilJson = false;

try {
    Notification::fromRequestBody('to nie jest JSON');
} catch (Throwable) {
    $rzucilJson = true;
}

sprawdz('tresc, ktora nie jest JSON-em, konczy sie wyjatkiem', true, $rzucilJson);
sprawdz('puste zadanie nie przechodzi', true, odrzucenie([], $config, $sesja, 1000, 'PLN') !== '');

sekcja('RegisterRequest: tresc zadania rejestracji');
$zadanie = new RegisterRequest(
    sessionId: $sesja,
    amountInMinorUnits: 1000,
    currency: 'pln',
    description: 'Zamowienie 41',
    email: 'klient@example.invalid',
    urlReturn: 'https://sklep.test/return',
    urlStatus: 'https://sklep.test/notify',
    country: 'pl',
    language: 'pl',
    client: 'Jan Kowalski',
    zip: '00-001',
    city: 'Warszawa'
);
$tresc = $zadanie->toPayload($config);

sprawdz('waluta idzie wielkimi literami', 'PLN', $tresc['currency']);
sprawdz('kraj idzie wielkimi literami', 'PL', $tresc['country']);
sprawdz('kwota jest liczba calkowita', 1000, $tresc['amount']);
sprawdz('podpis zgadza sie z osobno wyliczonym', Signature::forRegister($sesja, 12345, 1000, 'PLN', $config->crc), $tresc['sign']);
sprawdz('puste pola nieobowiazkowe nie trafiaja do zadania', false, array_key_exists('phone', $tresc));
sprawdz('wypelnione pole nieobowiazkowe trafia', 'Warszawa', $tresc['city']);
sprawdz('kodowanie jest zadeklarowane', 'UTF-8', $tresc['encoding']);

$zadanieObcyJezyk = new RegisterRequest(
    sessionId: $sesja,
    amountInMinorUnits: 100,
    currency: 'PLN',
    description: 'test',
    email: 'a@example.invalid',
    urlReturn: 'https://sklep.test/r',
    urlStatus: 'https://sklep.test/n',
    language: 'ja'
);
sprawdz('nieobslugiwany jezyk zamienia sie na angielski', 'en', $zadanieObcyJezyk->toPayload($config)['language']);

sekcja('OrderPaymentData: odczyt pola HikaShopa');
$zamowienieObiekt = (object) ['order_payment_params' => (object) ['p24_session_id' => $sesja]];
sprawdz('odczyt z obiektu', $sesja, OrderPaymentData::getString($zamowienieObiekt, OrderPaymentData::SESSION_ID));

$zamowienieTekst = (object) ['order_payment_params' => serialize((object) ['p24_session_id' => $sesja, 'p24_order_id' => 777])];
sprawdz('odczyt z pola po serialize', $sesja, OrderPaymentData::getString($zamowienieTekst, OrderPaymentData::SESSION_ID));
sprawdz('odczyt liczby z pola po serialize', 777, OrderPaymentData::getInt($zamowienieTekst, OrderPaymentData::P24_ORDER_ID));
sprawdz('brak pola daje wartosc domyslna', '', OrderPaymentData::getString(null, OrderPaymentData::SESSION_ID));
sprawdz('uszkodzone pole nie wysypuje odczytu', '', OrderPaymentData::getString((object) ['order_payment_params' => 'sieczka'], OrderPaymentData::SESSION_ID));

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $passed . ', niezdane: ' . $failed . PHP_EOL;

exit($failed === 0 ? 0 : 1);
