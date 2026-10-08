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

require __DIR__ . '/autoload.php';

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiResponse;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Endpoints;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Environment;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Notification;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Signature;

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

// Koszyk HikaShopa trzyma sume jako liczbe zmiennoprzecinkowa o pelnej
// precyzji, a baza zaokragla ja do pieciu miejsc. Tuz przy granicy pol
// grosza obie postacie daja inne grosze. Dlatego rejestracja transakcji
// i obsluga powiadomienia musza brac kwote z tego samego miejsca, z bazy.
sprawdz('suma z koszyka tuz pod granica pol grosza', 1000, Amount::toMinorUnit(10.0049999));
sprawdz('ta sama suma po zapisie w bazie daje inny grosz', 1001, Amount::toMinorUnit('10.00500'));
sprawdz('rowne pol grosza zaokragla sie w gore, jak w cenach HikaShopa', 101, Amount::toMinorUnit('1.00500'));

sekcja('ApiResponse: ktore odpowiedzi sa przyjeciem zadania');
// Rejestracja i weryfikacja odpowiadaja 200. Operacje, ktore cos zakladaja,
// odpowiadaja 201: tak specyfikacja opisuje obciazenie kodem BLIK.
$przyjete201 = ApiResponse::fromDecoded(201, ['data' => ['orderId' => 4300000001, 'message' => 'success'], 'responseCode' => 0]);
sprawdz('200 to przyjecie zadania', true, ApiResponse::fromDecoded(200, ['data' => ['token' => 'abc'], 'responseCode' => 0])->isSuccessful());
sprawdz('201 to takze przyjecie zadania', true, $przyjete201->isSuccessful());
sprawdz('dane z odpowiedzi 201 sa dostepne', 4300000001, $przyjete201->get('orderId'));
sprawdz('400 to odmowa', false, ApiResponse::fromDecoded(400, ['error' => 'Invalid amount', 'code' => 400])->isSuccessful());
sprawdz('401 to odmowa', false, ApiResponse::fromDecoded(401, ['error' => 'Incorrect authentication', 'code' => 401])->isSuccessful());
sprawdz('200 z polem error to odmowa', false, ApiResponse::fromDecoded(200, ['error' => 'cos poszlo zle'])->isSuccessful());
sprawdz('204 nie jest przyjeciem zadania', false, ApiResponse::fromDecoded(204, [])->isSuccessful());
sprawdz('500 to odmowa', false, ApiResponse::fromDecoded(500, ['error' => 'Internal error', 'code' => 500])->isSuccessful());

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
sprawdz('konfiguracja nie ma ustawienia zwrotow', false, property_exists($config, 'refundStatus'));
sprawdz(
    'stare ustawienie zwrotu w bazie jest ignorowane',
    false,
    property_exists(Config::fromPaymentParams((object) ['merchant_id' => '1', 'refund_status' => 'refunded']), 'refundStatus')
);

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

$zJezykiem = static fn (string $jezyk): string => (new RegisterRequest(
    sessionId: $sesja,
    amountInMinorUnits: 100,
    currency: 'PLN',
    description: 'test',
    email: 'a@example.invalid',
    urlReturn: 'https://sklep.test/r',
    urlStatus: 'https://sklep.test/n',
    language: $jezyk
))->toPayload($config)['language'];
sprawdz('rumunski jest na liscie jezykow P24', 'ro', $zJezykiem('ro'));
sprawdz('kod jezyka z regionem jest skracany', 'ro', $zJezykiem('ro-RO'));

sekcja('RegisterRequest: adres e-mail nie jest przycinany');
// P24 odrzuca adresy dluzsze niz 50 znakow (sandbox, 07.10.2026: 50 znakow
// przechodzi, 51 konczy sie bledem "Invalid email"). Przyciecie dawaloby
// inny adres, wiec za dlugi adres ma zatrzymac wywolujacy, nie my po cichu.
$adres50 = str_repeat('a', 38) . '@example.com';
$adres51 = str_repeat('a', 39) . '@example.com';
sprawdz('adres testowy ma dokladnie 50 znakow', 50, strlen($adres50));
sprawdz('adres o 50 znakach miesci sie w limicie', true, RegisterRequest::isEmailAccepted($adres50));
sprawdz('adres o 51 znakach juz nie', false, RegisterRequest::isEmailAccepted($adres51));
sprawdz('spacje wokol adresu nie licza sie do limitu', true, RegisterRequest::isEmailAccepted('  ' . $adres50 . '  '));
sprawdz('pusty adres nie jest sprawa limitu dlugosci', true, RegisterRequest::isEmailAccepted(''));

$zAdresem = static fn (string $adres): string => (new RegisterRequest(
    sessionId: $sesja,
    amountInMinorUnits: 100,
    currency: 'PLN',
    description: 'test',
    email: $adres,
    urlReturn: 'https://sklep.test/r',
    urlStatus: 'https://sklep.test/n'
))->toPayload($config)['email'];
sprawdz('za dlugi adres idzie w calosci, nie przyciety do cudzego', $adres51, $zAdresem($adres51));
sprawdz('spacje wokol adresu sa obcinane', 'a@example.invalid', $zAdresem('  a@example.invalid '));

sekcja('RegisterRequest: dane platnika (additional.PSU) dla BLIK-a w sklepie');
$psu = static fn (string $ip, string $ua = ''): array => (new RegisterRequest(
    sessionId: $sesja,
    amountInMinorUnits: 100,
    currency: 'PLN',
    description: 'test',
    email: 'a@example.invalid',
    urlReturn: 'https://sklep.test/r',
    urlStatus: 'https://sklep.test/n',
    clientIp: $ip,
    clientUserAgent: $ua
))->toPayload($config);

sprawdz('bez IP nie ma pola additional', false, array_key_exists('additional', $psu('')));
sprawdz('IPv4 trafia do PSU', '93.105.192.95', $psu('93.105.192.95', 'Chrome')['additional']['PSU']['IP'] ?? null);
sprawdz('przegladarka trafia do PSU', 'Chrome', $psu('93.105.192.95', 'Chrome')['additional']['PSU']['userAgent'] ?? null);
sprawdz('IPv6 jest przyjmowane', '2001:db8::1', $psu('2001:db8::1')['additional']['PSU']['IP'] ?? null);
sprawdz('pusta przegladarka nie wysyla userAgent', false, isset($psu('10.0.0.1')['additional']['PSU']['userAgent']));
sprawdz('bledne IP pomija PSU', false, array_key_exists('additional', $psu('to-nie-ip', 'Chrome')));
sprawdz('userAgent przyciety do 255 znakow', 255, mb_strlen($psu('10.0.0.1', str_repeat('a', 400))['additional']['PSU']['userAgent']));

sekcja('OrderPaymentData: odczyt pola HikaShopa');
$zamowienieObiekt = (object) ['order_payment_params' => (object) ['p24_session_id' => $sesja]];
sprawdz('odczyt z obiektu', $sesja, OrderPaymentData::getString($zamowienieObiekt, OrderPaymentData::SESSION_ID));

$zamowienieTekst = (object) ['order_payment_params' => serialize((object) ['p24_session_id' => $sesja, 'p24_order_id' => 777])];
sprawdz('odczyt z pola po serialize', $sesja, OrderPaymentData::getString($zamowienieTekst, OrderPaymentData::SESSION_ID));
sprawdz('odczyt liczby z pola po serialize', 777, OrderPaymentData::getInt($zamowienieTekst, OrderPaymentData::P24_ORDER_ID));
sprawdz('brak pola daje wartosc domyslna', '', OrderPaymentData::getString(null, OrderPaymentData::SESSION_ID));
sprawdz('uszkodzone pole nie wysypuje odczytu', '', OrderPaymentData::getString((object) ['order_payment_params' => 'sieczka'], OrderPaymentData::SESSION_ID));

sekcja('RegisterRequest: narzucona metoda platnosci');

/**
 * Buduje zadanie rejestracji z opcjonalnie narzucona metoda.
 *
 * @return array<string, mixed>
 */
function zadanieZMetoda(Config $config, ?int $metoda): array
{
    return (new RegisterRequest(
        sessionId: 'hika_9_abc',
        amountInMinorUnits: 5000,
        currency: 'PLN',
        description: 'test',
        email: 'a@example.invalid',
        urlReturn: 'https://sklep.test/r',
        urlStatus: 'https://sklep.test/n',
        method: $metoda
    ))->toPayload($config);
}

sprawdz('brak metody nie dodaje pola do zadania', false, array_key_exists('method', zadanieZMetoda($config, null)));
sprawdz('raty trafiaja do zadania jako method 303', 303, zadanieZMetoda($config, 303)['method']);
sprawdz('zero nie narzuca metody', false, array_key_exists('method', zadanieZMetoda($config, 0)));

sprawdz('domyslnie metoda nie jest narzucona', 0, Config::fromPaymentParams(null)->paymentMethodId);
sprawdz('ujemna wartosc jest sprowadzana do zera', 0, Config::fromPaymentParams((object) ['payment_method_id' => -5])->paymentMethodId);
sprawdz('raty odczytane z konfiguracji', 303, Config::fromPaymentParams((object) ['payment_method_id' => '303'])->paymentMethodId);

sekcja('Manifest: nazwa musi byc doslowna, nie kluczem jezykowym');
// HikaShop w czesci widokow wypisuje kolumne name wprost z bazy, bez
// JText::_(), wiec klucz jezykowy pokazywalby sie w panelu dokladnie
// tak, jak go zapisano. Wszystkie wtyczki platnosci HikaShopa trzymaja
// tu nazwe doslowna.
$manifest = simplexml_load_file(__DIR__ . '/../plugin/przelewy24.xml');
sprawdz('manifest jest poprawnym XML-em', true, $manifest !== false);

if ($manifest !== false) {
    $nazwa = trim((string) $manifest->name);
    sprawdz('nazwa nie jest kluczem jezykowym', false, str_starts_with($nazwa, 'PLG_'));
    sprawdz('nazwa jest niepusta', true, $nazwa !== '');
    sprawdz('grupa wtyczki to hikashoppayment', 'hikashoppayment', (string) $manifest['group']);
    sprawdz('glowny plik wskazany atrybutem plugin', 'przelewy24', (string) $manifest->files->filename[0]['plugin']);

    // Przy odinstalowaniu Joomla kasuje CALY katalog docelowy znacznika
    // media. Do 1.0.6 byl nim katalog obrazkow platnosci HikaShopa, wiec
    // odinstalowanie wtyczki usuwalo logotypy wszystkich metod platnosci.
    $celLogotypow = (string) $manifest->media['destination'];
    sprawdz('logotypy instaluja sie do wlasnego katalogu wtyczki', 'plg_hikashoppayment_przelewy24', $celLogotypow);
    sprawdz('znacznik media nie celuje w katalog HikaShopa', false, str_contains($celLogotypow, 'com_hikashop'));
    sprawdz('kod zwrotow nie jest juz czescia paczki', false, is_file(__DIR__ . '/../plugin/src/Payment/RefundService.php'));

    $wersjaManifest = trim((string) $manifest->version);
    $aktualizacje   = simplexml_load_file(__DIR__ . '/../przelewy24_update.xml');

    if ($aktualizacje !== false) {
        sprawdz(
            'wersja w serwerze aktualizacji zgadza sie z manifestem',
            $wersjaManifest,
            trim((string) $aktualizacje->update->version)
        );
        sprawdz(
            'nazwa w serwerze aktualizacji zgadza sie z manifestem',
            $nazwa,
            trim((string) $aktualizacje->update->name)
        );
    }
}

sekcja('Dane dostepowe testow: metoda platnosci ze sklepu');

require __DIR__ . '/dane-dostepowe.php';

$metodaSklepu = static fn (array $parametry): array => [
    'payment_id'     => 8,
    'payment_params' => serialize((object) $parametry),
];
$komplet = ['merchant_id' => '123456', 'pos_id' => '', 'crc_key' => 'abcdef0123456789', 'api_key' => 'klucz-api', 'test_mode' => '1'];

[$daneSklepu, $opisSklepu] = daneP24ZMetody($metodaSklepu($komplet + ['blik_in_shop' => '1']));
sprawdz('metoda sandboxowa ze sklepu jest uzyta', 'abcdef0123456789', $daneSklepu['crc_key'] ?? null);
sprawdz('opis wskazuje metode platnosci', 'metoda platnosci id=8', $opisSklepu);
sprawdz('puste pos_id przyjmuje identyfikator sprzedawcy', 123456, $daneSklepu['pos_id'] ?? null);
sprawdz(
    'do testow trafia tylko piec pol dostepowych',
    ['merchant_id', 'pos_id', 'crc_key', 'api_key', 'test_mode'],
    array_keys((array) $daneSklepu)
);

// Lokalna kopia sklepu bywa swieza kopia produkcji. Jej dane nie moga
// trafic do testow, ktore rejestruja transakcje.
[$daneProdukcji, $opisProdukcji] = daneP24ZMetody($metodaSklepu(['test_mode' => '0'] + $komplet));
sprawdz('metoda w trybie produkcyjnym jest pomijana', null, $daneProdukcji);
sprawdz('powod pominiecia wymienia tryb produkcyjny', true, str_contains($opisProdukcji, 'PRODUKCYJNYM'));

[$daneNiepelne] = daneP24ZMetody($metodaSklepu(['crc_key' => ''] + $komplet));
sprawdz('metoda bez klucza CRC jest pomijana', null, $daneNiepelne);

[$daneBezMetody, $opisBezMetody] = daneP24ZMetody('w sklepie nie ma opublikowanej metody przelewy24');
sprawdz('brak metody w sklepie nie daje danych', null, $daneBezMetody);
sprawdz('powod braku jest przekazany dalej', 'w sklepie nie ma opublikowanej metody przelewy24', $opisBezMetody);

[$daneSieczka] = daneP24ZMetody(['payment_id' => 8, 'payment_params' => 'to nie jest serialize']);
sprawdz('nieczytelne parametry metody nie daja danych', null, $daneSieczka);

sprawdz('dane produkcyjne z pliku nie udaja sandboxa', '0', uporzadkujDaneP24((object) (['test_mode' => '0'] + $komplet))['test_mode']);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $passed . ', niezdane: ' . $failed . PHP_EOL;

exit($failed === 0 ? 0 : 1);
