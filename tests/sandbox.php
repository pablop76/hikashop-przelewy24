<?php
/**
 * Test integracyjny przeciw SANDBOKSOWI P24.
 *
 * Uruchomienie:
 *   php tests/sandbox.php
 *
 * Wymaga pliku tests/credentials.local.php z danymi konta testowego
 * oraz lokalnej instalacji Joomli, z której bierzemy klienta HTTP.
 *
 * Test nigdy nie odzywa się do produkcji: Environment wymusza sandbox
 * dla każdej wartości test_mode innej niż dokładnie "0".
 */

define('_JEXEC', 1);

$credentialsPath = __DIR__ . '/credentials.local.php';

if (!is_file($credentialsPath)) {
    fwrite(STDERR, 'Brak pliku tests/credentials.local.php z danymi sandboxa.' . PHP_EOL);
    exit(2);
}

// Klient HTTP Joomli. Ścieżkę można nadpisać zmienną środowiskową,
// gdyby lokalna instalacja stała gdzie indziej.
$joomlaPath = getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap';
$autoload   = $joomlaPath . '/libraries/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, 'Nie znaleziono autoloadera Joomli: ' . $autoload . PHP_EOL);
    fwrite(STDERR, 'Ustaw zmienną JOOMLA_PATH na katalog instalacji Joomli.' . PHP_EOL);
    exit(2);
}

require $autoload;
require __DIR__ . '/autoload.php';

use Joomla\Http\HttpFactory;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\TransactionService;

$config = Config::fromPaymentParams((object) require $credentialsPath);

if (!$config->environment->isSandbox()) {
    fwrite(STDERR, 'PRZERWANO: konfiguracja nie wskazuje na sandbox.' . PHP_EOL);
    exit(2);
}

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

echo 'Srodowisko: ' . $config->environment->value . ' (' . $config->environment->baseUrl() . ')' . PHP_EOL;
echo 'Sprzedawca: ' . $config->merchantId . PHP_EOL;
echo 'Klucze:     wczytane z pliku lokalnego, nie wypisujemy ich' . PHP_EOL;

// Logger bez zapisu do HikaShopa; wypisujemy na ekran, z wymazanymi sekretami.
$logger = new Logger('przelewy24', false, $config->secrets(), static function (string $linia): void {
    echo '       log: ' . $linia . PHP_EOL;
});

$http   = (new HttpFactory())->getHttp(['timeout' => 45]);
$client = new ApiClient($config, $logger, '1.0.0', 'https://haskap.test', $http);
$usluga = new TransactionService($client, $config, $logger);

echo PHP_EOL . '1. Dane dostepowe (api/v1/testAccess)' . PHP_EOL;
$dostep = $usluga->testAccess();
wynik('P24 przyjmuje merchantId i klucz do raportow', $dostep);

if (!$dostep) {
    echo PHP_EOL . 'Dalsze testy pominiete: bez poprawnych danych nie ma czego sprawdzac.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . '2. Rejestracja transakcji (api/v1/transaction/register)' . PHP_EOL;

// 1,15 zl to jedna z kwot, na ktorych round($cena, 2) * 100 daje
// 114.99999999999999. Rejestrujemy wlasnie taka, zeby sprawdzic,
// czy nasze przeliczanie przechodzi przez prawdziwe API.
$kwotaZl    = 1.15;
$kwotaGrosze = Amount::toMinorUnit($kwotaZl);
$sessionId  = SessionId::generate(4242);

wynik('1,15 zl przeliczone na grosze', $kwotaGrosze === 115, $kwotaGrosze . ' gr');

$zadanie = new RegisterRequest(
    sessionId: $sessionId,
    amountInMinorUnits: $kwotaGrosze,
    currency: 'PLN',
    description: 'Test integracyjny HikaShop',
    email: 'p24-test@example.invalid',
    urlReturn: 'https://haskap.test/return',
    urlStatus: 'https://haskap.test/notify',
    country: 'PL',
    language: 'pl',
    client: 'P24 TEST'
);

$token = null;

try {
    $token = $usluga->register($zadanie);
    wynik('P24 zwrocilo token transakcji', is_string($token) && $token !== '', 'dlugosc ' . strlen((string) $token));
} catch (ApiException $e) {
    wynik('P24 zwrocilo token transakcji', false, $e->getMessage() . ' [HTTP ' . $e->getHttpStatus() . ']');
}

if ($token !== null) {
    echo PHP_EOL . '3. Adres strony platnosci' . PHP_EOL;
    $paywall = $client->paywallUrl($token);
    wynik('adres prowadzi do sandboxa', str_starts_with($paywall, 'https://sandbox.przelewy24.pl/trnRequest/'));
    echo '       ' . $paywall . PHP_EOL;

    echo PHP_EOL . '4. Odczyt transakcji po sessionId' . PHP_EOL;
    // Ustalone doswiadczalnie 23.09.2026 na sandboxie: dopoki nikt nie
    // zaplacil, P24 odpowiada na ten endpoint kodem 404 "Transaction not
    // found". Zarejestrowanie transakcji nie wystarczy, zeby dalo sie ja
    // odczytac. Wniosek dla wtyczki: strona powrotu klienta NIE MOZE
    // opierac sie na tym endpoincie, bo dla nieoplaconej platnosci nie
    // dostanie zadnej informacji.
    $transakcja = $usluga->findBySessionId($sessionId);
    wynik('nieoplacona transakcja nie jest jeszcze widoczna po sessionId', $transakcja === null);

    echo PHP_EOL . '5. Powtorna rejestracja tego samego sessionId' . PHP_EOL;
    // Ustalone doswiadczalnie 23.09.2026 na sandboxie: P24 PRZYJMUJE
    // powtorzony sessionId i zwraca drugi token. Nie potwierdza sie wiec
    // teza, ze ponowienie platnosci odbija sie od P24 z powodu
    // powtorzonego identyfikatora sesji.
    //
    // Wlasny sessionId na kazda probe i tak zostaje, bo dwie transakcje
    // o tym samym identyfikatorze sprawiaja, ze powiadomienie przestaje
    // jednoznacznie wskazywac probe, ktorej dotyczy.
    $drugiToken = null;

    try {
        $drugiToken = $usluga->register($zadanie);
        wynik('P24 przyjmuje powtorzony sessionId', is_string($drugiToken) && $drugiToken !== '');
        // P24 zwraca ten sam token, czyli rejestracja jest idempotentna
        // wzgledem sessionId przy niezmienionej kwocie. Ponowienie
        // platnosci prowadzi klienta na te sama strone platnosci.
        wynik('powtorzenie zwraca ten sam token co pierwsza proba', $drugiToken === $token);
    } catch (ApiException $e) {
        wynik('P24 przyjmuje powtorzony sessionId', false, 'HTTP ' . $e->getHttpStatus() . ', ' . $e->getMessage());
    }

    echo PHP_EOL . '6. Weryfikacja transakcji, za ktora nikt nie zaplacil' . PHP_EOL;
    try {
        $potwierdzona = $usluga->verify($sessionId, 1, $kwotaGrosze, 'PLN');
        wynik('niezaplacona transakcja nie jest potwierdzana', $potwierdzona === false);
    } catch (ApiException $e) {
        wynik('niezaplacona transakcja nie jest potwierdzana', true, 'P24 odrzucilo weryfikacje: HTTP ' . $e->getHttpStatus());
    }
}

echo PHP_EOL . '7. Bledne dane dostepowe' . PHP_EOL;
$zleDane = new Config(
    merchantId: $config->merchantId,
    posId: $config->posId,
    crc: $config->crc,
    apiKey: 'zupelnie-bledny-klucz-api-0000000',
    environment: $config->environment,
    debug: false,
    verifiedStatus: 'confirmed',
    invalidStatus: 'cancelled',
    pendingStatus: 'created'
);
$zlaUsluga = new TransactionService(
    new ApiClient($zleDane, $logger, '1.0.0', 'https://haskap.test', $http),
    $zleDane,
    $logger
);
wynik('bledny klucz API jest odrzucany', $zlaUsluga->testAccess() === false);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
