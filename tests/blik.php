<?php
/**
 * Testy platnosci BLIK wpisywanej w kasie.
 *
 * Uruchomienie:
 *   php tests/blik.php
 *
 * Siec podstawiona atrapa transportu HTTP, wiec sprawdzamy prawdziwa
 * tresc zadania do P24 i prawdziwe odwzorowanie kodow odrzucenia,
 * bez wysylania czegokolwiek na zywo.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Http\TransportInterface;
use Joomla\Uri\UriInterface;
use Laminas\Diactoros\Stream;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikError;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikService;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\BlikException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;

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

final class TransportBlik implements TransportInterface
{
    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    public function __construct(
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"orderId":123456}}'
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

function uslugaBlik(TransportBlik $transport, Config $config): BlikService
{
    $logger = new Logger('przelewy24', false, $config->secrets(), static function (): void {});
    $client = new ApiClient($config, $logger, '1.0.0', 'https://haskap.test', new Http([], $transport));

    return new BlikService($client, $logger);
}

$metoda = metodaPlatnosciP24();
$config = $metoda['config'];

echo 'Metoda platnosci: id=' . $metoda['id'] . ', srodowisko ' . $config->environment->value . PHP_EOL;

echo PHP_EOL . '1. Ksztalt kodu BLIK' . PHP_EOL;
wynik('szesc cyfr jest poprawne', true, BlikService::isValidCode('123456'));
wynik('piec cyfr to za malo', false, BlikService::isValidCode('12345'));
wynik('siedem cyfr to za duzo', false, BlikService::isValidCode('1234567'));
wynik('litery nie przechodza', false, BlikService::isValidCode('12a456'));
wynik('pusty kod nie przechodzi', false, BlikService::isValidCode(''));

echo PHP_EOL . '2. Sprzatanie tego, co wpisal klient' . PHP_EOL;
wynik('spacje w srodku znikaja', '123456', BlikService::normaliseCode('123 456'));
wynik('myslnik znika', '123456', BlikService::normaliseCode('123-456'));
wynik('spacje po bokach znikaja', '123456', BlikService::normaliseCode('  123456  '));
wynik('kod ze spacjami jest poprawny po znormalizowaniu', true, BlikService::isValidCode(BlikService::normaliseCode('12 34 56')));

echo PHP_EOL . '3. Odwzorowanie kodow odrzucenia P24' . PHP_EOL;
wynik('30 to kod przeterminowany', BlikError::CodeExpired, BlikError::fromCode(30));
wynik('35 to kod juz uzyty', BlikError::CodeUsed, BlikError::fromCode(35));
wynik('61 to brak srodkow', BlikError::InsufficientFunds, BlikError::fromCode(61));
wynik('60 to przekroczony limit', BlikError::LimitExceeded, BlikError::fromCode(60));
wynik('62 to odmowa banku', BlikError::IssuerDeclined, BlikError::fromCode(62));
wynik('nieznany numer daje blad ogolny', BlikError::GeneralError, BlikError::fromCode(9999));
wynik('brak numeru daje blad ogolny', BlikError::GeneralError, BlikError::fromCode(null));

echo PHP_EOL . '4. Czy wolno prosic o nowy kod' . PHP_EOL;
wynik('przeterminowany kod pozwala ponowic', true, BlikError::CodeExpired->allowsRetry());
wynik('zly kod pozwala ponowic', true, BlikError::WrongCode->allowsRetry());
wynik('brak srodkow nie pozwala ponowic', false, BlikError::InsufficientFunds->allowsRetry());
wynik('odmowa banku nie pozwala ponowic', false, BlikError::IssuerDeclined->allowsRetry());
wynik('tylko zuzyty kod jest oznaczony jako skonsumowany', true, BlikError::CodeUsed->isCodeConsumed());
wynik('przeterminowany nie jest skonsumowany', false, BlikError::CodeExpired->isCodeConsumed());

echo PHP_EOL . '5. Komunikat dla kazdej przyczyny odrzucenia' . PHP_EOL;

$pl = parse_ini_file(__DIR__ . '/../plugin/language/pl-PL/plg_hikashoppayment_przelewy24.ini');
$en = parse_ini_file(__DIR__ . '/../plugin/language/en-GB/plg_hikashoppayment_przelewy24.ini');

$brakujacePl = [];
$brakujaceEn = [];

foreach (BlikError::cases() as $przyczyna) {
    $klucz = $przyczyna->languageKey();

    if (!isset($pl[$klucz])) {
        $brakujacePl[] = $klucz;
    }

    if (!isset($en[$klucz])) {
        $brakujaceEn[] = $klucz;
    }
}

wynik('kazda przyczyna ma komunikat po polsku', [], $brakujacePl);
wynik('kazda przyczyna ma komunikat po angielsku', [], $brakujaceEn);
wynik('przyczyn jest tyle, ile obslugujemy', 14, count(BlikError::cases()));

echo PHP_EOL . '6. Tresc zadania wyslanego do P24' . PHP_EOL;

$transport = new TransportBlik();
$p24OrderId = uslugaBlik($transport, $config)->chargeByCode('TOKEN-TESTOWY-123', '123456');

wynik('wyslano dokladnie jedno zadanie', 1, count($transport->wywolania));
wynik('metoda HTTP to POST', 'POST', $transport->wywolania[0]['metoda']);
wynik('adres to endpoint BLIK', true, str_contains($transport->wywolania[0]['adres'], 'api/v1/paymentMethod/blik/chargeByCode'));
wynik('adres prowadzi do sandboxa', true, str_contains($transport->wywolania[0]['adres'], 'sandbox.przelewy24.pl'));

$tresc = json_decode((string) $transport->wywolania[0]['tresc'], true);

wynik('token przekazany', 'TOKEN-TESTOWY-123', $tresc['token'] ?? null);
wynik('kod BLIK przekazany', '123456', $tresc['blikCode'] ?? null);
wynik('nic wiecej nie idzie w zadaniu', ['token', 'blikCode'], array_keys($tresc));
wynik('zwrocono identyfikator transakcji P24', 123456, $p24OrderId);

$transportSpacje = new TransportBlik();
uslugaBlik($transportSpacje, $config)->chargeByCode('TOKEN', '12 34 56');
$trescSpacje = json_decode((string) $transportSpacje->wywolania[0]['tresc'], true);
wynik('kod ze spacjami idzie do P24 juz oczyszczony', '123456', $trescSpacje['blikCode'] ?? null);

echo PHP_EOL . '7. Czego BLIK musi odmowic' . PHP_EOL;

/**
 * Zwraca przyczyne odrzucenia albo null, gdy obciazenie przeszlo.
 */
function odrzucenieBlik(TransportBlik $transport, Config $config, string $kod = '123456'): ?BlikError
{
    try {
        uslugaBlik($transport, $config)->chargeByCode('TOKEN', $kod);

        return null;
    } catch (BlikException $e) {
        return $e->getReason();
    }
}

wynik('kod o zlej dlugosci nie idzie nawet do P24', BlikError::WrongCode, odrzucenieBlik(new TransportBlik(), $config, '12345'));

$transportPusty = new TransportBlik();
odrzucenieBlik($transportPusty, $config, '12345');
wynik('przy zlym kodzie nie wykonano zadnego zadania', 0, count($transportPusty->wywolania));

wynik(
    'przeterminowany kod jest rozpoznany',
    BlikError::CodeExpired,
    odrzucenieBlik(new TransportBlik(400, '{"error":"Ticket expired","code":30}'), $config)
);
wynik(
    'zuzyty kod jest rozpoznany',
    BlikError::CodeUsed,
    odrzucenieBlik(new TransportBlik(400, '{"error":"Ticket used","code":35}'), $config)
);
wynik(
    'brak srodkow jest rozpoznany',
    BlikError::InsufficientFunds,
    odrzucenieBlik(new TransportBlik(400, '{"error":"No funds","code":61}'), $config)
);
wynik(
    'odpowiedz bez identyfikatora transakcji jest bledem',
    BlikError::GeneralError,
    odrzucenieBlik(new TransportBlik(200, '{"data":{}}'), $config)
);

echo PHP_EOL . '8. Wtyczka' . PHP_EOL;

$wtyczka = hikashop_import('hikashoppayment', 'przelewy24');

wynik('metoda needCC() istnieje', true, method_exists($wtyczka, 'needCC'));
wynik('metoda onPaymentSave() istnieje', true, method_exists($wtyczka, 'onPaymentSave'));

// Przy wylaczonym BLIK-u pole nie moze pojawic sie w kasie.
$metodaBezBlik = (object) ['payment_params' => (object) ['blik_in_shop' => '0'], 'custom_html' => ''];
$wtyczka->needCC($metodaBezBlik);
wynik('przy wylaczonym BLIK-u pole sie nie pojawia', '', $metodaBezBlik->custom_html);

$metodaZBlik = (object) ['payment_params' => (object) ['blik_in_shop' => '1'], 'custom_html' => ''];
$wtyczka->needCC($metodaZBlik);
wynik('przy wlaczonym BLIK-u pole sie pojawia', true, str_contains($metodaZBlik->custom_html, 'hikashop_przelewy24_blik_code'));
wynik('pole ma tryb numeryczny', true, str_contains($metodaZBlik->custom_html, 'inputmode="numeric"'));
wynik('pole nie podpowiada z historii przegladarki', true, str_contains($metodaZBlik->custom_html, 'autocomplete="off"'));

$config0 = Config::fromPaymentParams((object) ['blik_in_shop' => '0']);
$config1 = Config::fromPaymentParams((object) ['blik_in_shop' => '1']);
wynik('domyslnie BLIK w kasie jest wylaczony', false, Config::fromPaymentParams(null)->blikInShop);
wynik('zero wylacza', false, $config0->blikInShop);
wynik('jedynka wlacza', true, $config1->blikInShop);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
