<?php
/**
 * Test strony oczekiwania na potwierdzenie BLIK i powiadomienia o odrzuceniu.
 *
 * Uruchomienie:
 *   php tests/blik-oczekiwanie.php
 *
 * Po przyjęciu kodu BLIK klient zostaje w sklepie na stronie „Potwierdź
 * płatność w aplikacji banku”. Do 1.0.8 ta strona stała w miejscu: po
 * potwierdzeniu zamówienie się opłacało, a klient dalej czytał, że ma
 * potwierdzić (serwer testowy, 09.10.2026). Po odrzuceniu w banku czekał
 * bez końca, bo P24 podaje powód tylko w dodatkowym powiadomieniu BLIK.
 *
 * Sieć jest podstawiona atrapą, reszta dzieje się naprawdę: zamówienie
 * w bazie, metoda płatności, zapis danych transakcji. Zamówienie testowe
 * jest kasowane na końcu niezależnie od wyniku.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Joomla\CMS\Language\Text;
use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Http\TransportInterface;
use Joomla\Uri\UriInterface;
use Laminas\Diactoros\Stream;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikError;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikNotification;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\SignatureException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;

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
 * Atrapa sieci: odpowiedź dobierana po adresie pytania.
 */
final class TransportOczekiwania implements TransportInterface
{
    public const STAN        = 'transaction/by/sessionId';
    public const WERYFIKACJA = 'transaction/verify';
    public const REJESTRACJA = 'transaction/register';

    /** Tak P24 odpowiada na pytanie o transakcję, przy której nikt nie wybrał metody. */
    public const NIEZNANA = [404, '{"error":"Transaction not found","responseCode":0}'];

    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    /**
     * @param  array<string, array{0: int, 1: string}>  $poAdresie
     */
    public function __construct(
        private array $poAdresie = [self::STAN => self::NIEZNANA],
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"token":"TOKENBLIK123"},"responseCode":0}'
    ) {
    }

    public function request($method, UriInterface $uri, $data = null, array $headers = [], $timeout = null, $userAgent = null)
    {
        $adres = (string) $uri;

        $this->wywolania[] = ['metoda' => $method, 'adres' => $adres, 'tresc' => $data];

        [$kod, $tresc] = [$this->kodHttp, $this->odpowiedz];

        foreach ($this->poAdresie as $fragment => $odpowiedz) {
            if (str_contains($adres, $fragment)) {
                [$kod, $tresc] = $odpowiedz;

                break;
            }
        }

        $strumien = new Stream('php://memory', 'rw');
        $strumien->write($tresc);

        return new Response($strumien, $kod);
    }

    /** @return list<array{metoda: string, adres: string, tresc: mixed}> */
    public function pytania(string $fragment): array
    {
        return array_values(array_filter(
            $this->wywolania,
            static fn (array $wywolanie): bool => str_contains($wywolanie['adres'], $fragment)
        ));
    }

    public function ile(string $fragment): int
    {
        return count($this->pytania($fragment));
    }

    public static function isSupported()
    {
        return true;
    }
}

/**
 * Wtyczka z atrapą sieci, podstawioną treścią powiadomienia
 * i zapamiętanym przekierowaniem zamiast wyjścia.
 */
final class WtyczkaOczekiwania extends Przelewy24
{
    public ?TransportOczekiwania $transport = null;

    public ?string $przekierowanie = null;

    public string $trescPowiadomienia = '';

    /** Wymusza ustawienie „Kod BLIK w kasie” bez zapisu w konfiguracji metody. */
    public ?string $blikWKasie = null;

    /** @var list<mixed> */
    public array $alerty = [];

    /** @var list<string> */
    public array $dziennik = [];

    public function modifyOrder(&$order_id, $order_status, $history = null, $email = null, $payment_params = null)
    {
        if ($order_id === false) {
            $this->alerty[] = $email;

            return;
        }

        parent::modifyOrder($order_id, $order_status, $history, $email, $payment_params);
    }

    public function loadPaymentParams(&$order)
    {
        $jest = parent::loadPaymentParams($order);

        if ($jest && $this->blikWKasie !== null) {
            // Kopia, żeby zmiana nie przeciekła do metody płatności
            // trzymanej przez HikaShopa w pamięci.
            $this->payment_params               = clone $this->payment_params;
            $this->payment_params->blik_in_shop = $this->blikWKasie;
        }

        return $jest;
    }

    protected function buildLogger(Config $config)
    {
        return new Logger($this->name, true, $config->secrets(), function (string $wiersz): void {
            $this->dziennik[] = $wiersz;
        });
    }

    protected function buildClient(Config $config, Logger $logger)
    {
        return new ApiClient($config, $logger, self::VERSION, 'https://haskap.test', new Http([], $this->transport));
    }

    protected function redirectTo($url)
    {
        $this->przekierowanie = $url;
    }

    protected function readNotificationBody()
    {
        return $this->trescPowiadomienia;
    }

    public function adresStanu($order): string
    {
        return $this->buildBlikStatusUrl($order);
    }

    public function znacznikStanu($order): string
    {
        return $this->statusToken($order);
    }

    public function adresPowiadomieniaBlik($order): string
    {
        return $this->buildBlikNotifyUrl($order);
    }

    public function znacznikPowrotu($order): string
    {
        return $this->returnToken($order);
    }

    public function znacznikPonowienia($order): string
    {
        return $this->retryToken($order);
    }

    /**
     * Strona oczekiwania, taka jak po przyjęciu kodu BLIK.
     */
    public function widokOczekiwania(string $adresStanu, string $adresSprawdzenia): string
    {
        $this->p24_blik_pending    = true;
        $this->p24_blik_status_url = $adresStanu;
        $this->p24_blik_check_url  = $adresSprawdzenia;

        return $this->renderPage('end');
    }
}

// --- przygotowanie ---------------------------------------------------

Joomla\CMS\Factory::getApplication()->getLanguage()->load('plg_hikashoppayment_przelewy24', JPATH_ADMINISTRATOR);

$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();
$metoda = metodaPlatnosciP24();
$config = $metoda['config'];

$sessionId  = SessionId::generate(999997);
$orderToken = bin2hex(random_bytes(16));

$parametryPoczatkowe = static fn (): string => serialize((object) [
    OrderPaymentData::SESSION_ID => $sessionId,
    OrderPaymentData::AMOUNT     => 1234,
    OrderPaymentData::CURRENCY   => 'PLN',
    OrderPaymentData::ATTEMPTS   => 1,
]);

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_order
        (order_number, order_created, order_modified, order_status, order_type,
         order_full_price, order_currency_id, order_payment_id, order_payment_method,
         order_token, order_payment_params)
    VALUES
        (:numer, :utworzone, :utworzone, 'created', 'sale',
         12.34, 125, :platnosc, 'przelewy24',
         :token, :parametry)
")->execute([
    ':numer'     => 'P24BLIK' . random_int(1000, 9999),
    ':utworzone' => time(),
    ':platnosc'  => $metoda['id'],
    ':token'     => $orderToken,
    ':parametry' => $parametryPoczatkowe(),
]);

$orderId = (int) $pdo->lastInsertId();

echo 'Zamowienie testowe: ' . $orderId . PHP_EOL;

register_shutdown_function(static function () use ($pdo, $prefix, $orderId): void {
    $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $orderId]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $orderId]);
});

$ustawStatus = static function (string $status) use ($pdo, $prefix, $orderId): void {
    $pdo->prepare("UPDATE {$prefix}hikashop_order SET order_status = :s WHERE order_id = :id")
        ->execute([':s' => $status, ':id' => $orderId]);
};

$daneTransakcji = static function () use ($pdo, $prefix, $orderId): object {
    $q = $pdo->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    return (object) (array) @unserialize((string) $q->fetchColumn());
};

$statusZamowienia = static function () use ($pdo, $prefix, $orderId): string {
    $q = $pdo->prepare("SELECT order_status FROM {$prefix}hikashop_order WHERE order_id = :id");
    $q->execute([':id' => $orderId]);

    return (string) $q->fetchColumn();
};

$wpisowHistorii = static function () use ($pdo, $prefix, $orderId): int {
    $q = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}hikashop_history WHERE history_order_id = :id");
    $q->execute([':id' => $orderId]);

    return (int) $q->fetchColumn();
};

/**
 * Cofa zamówienie do stanu „złożone, kod BLIK przyjęty, nic więcej nie wiadomo”.
 *
 * @param  array<string, mixed>  $dodatkowe  pola dopisane do danych transakcji
 */
$odNowa = static function (array $dodatkowe = []) use ($pdo, $prefix, $orderId, $parametryPoczatkowe): void {
    $parametry = (array) unserialize($parametryPoczatkowe());

    $pdo->prepare("UPDATE {$prefix}hikashop_order SET order_status = 'created', order_payment_params = :p WHERE order_id = :id")
        ->execute([':id' => $orderId, ':p' => serialize((object) ($dodatkowe + $parametry))]);
};

/**
 * @return array{0: int, 1: string}
 */
$stanWP24 = static function (int $status, int $kwota = 1234) use ($sessionId): array {
    return [200, json_encode([
        'data' => [
            'orderId'       => 4321987,
            'sessionId'     => $sessionId,
            'status'        => $status,
            'amount'        => $kwota,
            'currency'      => 'PLN',
            'paymentMethod' => 181,
        ],
        'responseCode' => 0,
    ])];
};

$zaplacone = static fn (array $stan): TransportOczekiwania => new TransportOczekiwania([
    TransportOczekiwania::STAN        => $stan,
    TransportOczekiwania::WERYFIKACJA => [200, '{"data":{"status":"success"},"responseCode":0}'],
]);

$tekst = static fn (string $klucz): string => htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_' . $klucz), ENT_QUOTES, 'UTF-8');

$nowaWtyczka = static function (?TransportOczekiwania $atrapa = null): WtyczkaOczekiwania {
    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaOczekiwania($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
    $wtyczka->transport = $atrapa ?? new TransportOczekiwania();

    return $wtyczka;
};

/**
 * Czyści parametry żądania, żeby jedna sekcja nie podpowiadała drugiej.
 */
$wejscie = static function (array $parametry): void {
    $input = Joomla\CMS\Factory::getApplication()->input;

    foreach (['p24_action', 'order_id', 'p24_status', 'p24_ask', 'p24_return', 'p24_retry', 'order_token'] as $klucz) {
        $input->set($klucz, null);
    }

    foreach ($parametry as $klucz => $wartosc) {
        $input->set($klucz, $wartosc);
    }
};

/**
 * Pyta o wynik tak, jak robi to skrypt strony oczekiwania.
 *
 * @return array{surowa: mixed, odp: array<string, mixed>, wtyczka: WtyczkaOczekiwania, atrapa: TransportOczekiwania}
 */
$zapytaj = static function (string $znacznik, bool $pytajP24 = false, ?TransportOczekiwania $atrapa = null) use ($orderId, $nowaWtyczka, $wejscie): array {
    $wtyczka = $nowaWtyczka($atrapa);

    $wejscie([
        'p24_action' => 'blik_status',
        'order_id'   => $orderId,
        'p24_status' => $znacznik,
        'p24_ask'    => $pytajP24 ? 1 : null,
    ]);

    $statusy = [];
    $surowa  = $wtyczka->onPaymentNotification($statusy);
    $odp     = is_string($surowa) ? (json_decode($surowa, true) ?: []) : [];

    return ['surowa' => $surowa, 'odp' => $odp, 'wtyczka' => $wtyczka, 'atrapa' => $wtyczka->transport];
};

/**
 * Dostarcza powiadomienie BLIK tak, jak robi to P24.
 *
 * @return array{wynik: mixed, wtyczka: WtyczkaOczekiwania}
 */
$powiadom = static function (string $tresc, ?string $znacznikAdresu = null) use ($orderId, $orderToken, $nowaWtyczka, $wejscie): array {
    $wtyczka = $nowaWtyczka();
    $wtyczka->trescPowiadomienia = $tresc;

    $wejscie([
        'p24_action'  => 'blik_notify',
        'order_id'    => $orderId,
        'order_token' => $znacznikAdresu ?? md5($orderToken),
    ]);

    $statusy = [];

    return ['wynik' => $wtyczka->onPaymentNotification($statusy), 'wtyczka' => $wtyczka];
};

/**
 * Powrót klienta na stronę, na którą odsyła strona oczekiwania.
 */
$wroc = static function (string $znacznik, ?TransportOczekiwania $atrapa = null) use ($orderId, $nowaWtyczka, $wejscie): array {
    $wtyczka = $nowaWtyczka($atrapa);

    $wejscie(['p24_action' => 'return', 'order_id' => $orderId, 'p24_return' => $znacznik]);

    $statusy = [];

    return ['wynik' => $wtyczka->onPaymentNotification($statusy), 'wtyczka' => $wtyczka, 'atrapa' => $wtyczka->transport];
};

/**
 * Treść powiadomienia BLIK podpisana tak, jak podpisuje ją P24.
 *
 * @param  array<string, mixed>  $result
 */
$powiadomienieBlik = static function (array $result, ?string $sesja = null, bool $wData = false, ?string $crc = null) use ($config, $sessionId): string {
    $pola = [
        'orderId'   => 4321987,
        'sessionId' => $sesja ?? $sessionId,
        'method'    => 181,
        'result'    => $result,
    ];

    $pola['sign'] = hash('sha384', json_encode($pola + ['crc' => $crc ?? $config->crc], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return json_encode($wData ? ['data' => $pola] : $pola, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};

$zamowienie = (object) ['order_id' => $orderId, 'order_token' => $orderToken];
$pomocnik   = $nowaWtyczka();
$znacznik   = $pomocnik->znacznikStanu($zamowienie);

// --- testy -----------------------------------------------------------

echo PHP_EOL . '1. Adresy' . PHP_EOL;

$adresStanu = $pomocnik->adresStanu($zamowienie);

wynik('pytanie o wynik idzie do wtyczki', str_contains($adresStanu, 'task=notify') && str_contains($adresStanu, 'p24_action=blik_status') && str_contains($adresStanu, 'order_id=' . $orderId), $adresStanu);
// Odpowiedzią jest JSON dla skryptu. Z tym parametrem żądanie obsłużyłby
// komponent i odpowiedź przyszłaby w szablonie witryny.
wynik('odpowiedz bez szablonu witryny', !str_contains($adresStanu, 'skip_system_notification'));
wynik('adres wzgledny, zawsze ta sama domena co strona', !preg_match('#^https?://#', $adresStanu), $adresStanu);
wynik('niesie wlasny znacznik', str_contains($adresStanu, 'p24_status=' . $znacznik)
    && $znacznik !== $pomocnik->znacznikPowrotu($zamowienie)
    && $znacznik !== $pomocnik->znacznikPonowienia($zamowienie)
    && $znacznik !== md5($orderToken) && $znacznik !== $orderToken);

$adresBlik = $pomocnik->adresPowiadomieniaBlik($zamowienie);

wynik('powiadomienie BLIK idzie do wtyczki pod wlasna akcje', str_contains($adresBlik, 'task=notify') && str_contains($adresBlik, 'p24_action=blik_notify') && str_contains($adresBlik, 'order_id=' . $orderId), $adresBlik);
wynik('powiadomienie BLIK ma znacznik zamowienia i pelny adres', str_contains($adresBlik, 'order_token=' . md5($orderToken)) && (bool) preg_match('#^https?://#', $adresBlik));

echo PHP_EOL . '2. Rejestracja zamawia powiadomienie BLIK' . PHP_EOL;

$rejestracja = static fn (string $adres): array => (new RegisterRequest(
    sessionId: $sessionId,
    amountInMinorUnits: 1234,
    currency: 'PLN',
    description: 'Test',
    email: 'p24-test@example.invalid',
    urlReturn: 'https://haskap.test/return',
    urlStatus: 'https://haskap.test/notify',
    urlBlikNotification: $adres
))->toPayload($config);

wynik('adres trafia do pola urlCardPaymentNotification', ($rejestracja('https://haskap.test/blik')['urlCardPaymentNotification'] ?? null) === 'https://haskap.test/blik');
wynik('bez adresu pola nie ma', !array_key_exists('urlCardPaymentNotification', $rejestracja('')));

foreach (['1' => true, '0' => false] as $ustawienie => $oczekiwane) {
    $odNowa();

    $wtyczka = $nowaWtyczka();
    $wtyczka->blikWKasie = (string) $ustawienie;

    $wejscie(['p24_action' => 'retry', 'order_id' => $orderId, 'p24_retry' => $pomocnik->znacznikPonowienia($zamowienie)]);

    $statusy = [];
    $wtyczka->onPaymentNotification($statusy);

    $tresc = json_decode((string) ($wtyczka->transport->pytania(TransportOczekiwania::REJESTRACJA)[0]['tresc'] ?? ''), true) ?: [];
    $adres = (string) ($tresc['urlCardPaymentNotification'] ?? '');

    wynik(
        'kod BLIK w kasie = ' . $ustawienie . ': ' . ($oczekiwane ? 'wtyczka zamawia powiadomienie BLIK' : 'wtyczka go nie zamawia'),
        $tresc !== [] && ($oczekiwane ? str_contains($adres, 'p24_action=blik_notify') : $adres === ''),
        $adres
    );
}

echo PHP_EOL . '3. Pytanie o wynik: zadania odrzucone' . PHP_EOL;

$odNowa();

$r = $zapytaj('', true);
wynik('pusty znacznik: stan invalid, P24 nie jest pytane', ($r['odp']['state'] ?? '') === 'invalid' && count($r['atrapa']->wywolania) === 0, (string) $r['surowa']);
$r = $zapytaj($pomocnik->znacznikPowrotu($zamowienie), true);
wynik('znacznik powrotu nie otwiera pytania o wynik', ($r['odp']['state'] ?? '') === 'invalid' && count($r['atrapa']->wywolania) === 0);
$r = $zapytaj(md5($orderToken), true);
wynik('znacznik powiadomien P24 tez nie', ($r['odp']['state'] ?? '') === 'invalid' && !isset($r['odp']['redirect']));

echo PHP_EOL . '4. Pytanie o wynik: platnosc w toku' . PHP_EOL;

$r = $zapytaj($znacznik);
wynik('odpowiedz jest poprawnym JSON-em', is_string($r['surowa']) && json_decode($r['surowa']) !== null, (string) $r['surowa']);
// Skrypt strony wyłuskuje obiekt tym samym wyrażeniem.
wynik('odpowiedz pasuje do wyrazenia, ktorym czyta ja skrypt', is_string($r['surowa']) && preg_match('/\{"state":"[a-z]+"[^{}]*\}/', $r['surowa']) === 1);
wynik('bez prosby o pytanie P24: stan waiting', ($r['odp']['state'] ?? '') === 'waiting' && !isset($r['odp']['redirect']));
wynik('bez prosby o pytanie P24: zadnego zadania do P24', count($r['atrapa']->wywolania) === 0, (string) count($r['atrapa']->wywolania));

$r = $zapytaj($znacznik, true);
wynik('P24 nie zna transakcji: waiting', ($r['odp']['state'] ?? '') === 'waiting');
wynik('jedno pytanie o stan, bez weryfikacji i rejestracji', $r['atrapa']->ile(TransportOczekiwania::STAN) === 1 && count($r['atrapa']->wywolania) === 1);
wynik('pytanie dotyczy biezacej sesji', str_ends_with($r['atrapa']->wywolania[0]['adres'], '/' . $sessionId));

// Tak wygląda w P24 i płatność trwająca, i odrzucona przez bank.
$r = $zapytaj($znacznik, true, new TransportOczekiwania([TransportOczekiwania::STAN => $stanWP24(0)]));
wynik('transakcja bez wplaty: waiting, zamowienie nieoplacone', ($r['odp']['state'] ?? '') === 'waiting' && $statusZamowienia() === 'created');
wynik('transakcja bez wplaty: bez weryfikacji', $r['atrapa']->ile(TransportOczekiwania::WERYFIKACJA) === 0);

// Wcześniejsze próby sprawdza strona powrotu. Odpytywanie co kilka sekund
// nie może mnożyć pytań do P24 przez liczbę ponowień.
$odNowa([OrderPaymentData::SESSIONS => [SessionId::generate(999997), SessionId::generate(999997)]]);
$r = $zapytaj($znacznik, true);
wynik('przy trzech sesjach zamowienia pytanie tylko o biezaca', $r['atrapa']->ile(TransportOczekiwania::STAN) === 1 && str_ends_with($r['atrapa']->wywolania[0]['adres'], '/' . $sessionId), (string) $r['atrapa']->ile(TransportOczekiwania::STAN));

$odNowa();
$r = $zapytaj($znacznik, true, new TransportOczekiwania([], 500, '{"error":"blad","code":500}'));
wynik('P24 nie odpowiada: waiting, skrypt zapyta ponownie', ($r['odp']['state'] ?? '') === 'waiting' && $statusZamowienia() === 'created');
wynik('P24 nie odpowiada: blad w dzienniku', count(preg_grep('/\[BŁĄD\].*wyniku płatności BLIK/u', $r['wtyczka']->dziennik)) === 1);

echo PHP_EOL . '5. Pytanie o wynik: zaplata potwierdzona' . PHP_EOL;

// Powiadomienie nie dotarło albo jeszcze nie dotarło: wpłata jest w P24.
$odNowa();
$r  = $zapytaj($znacznik, true, $zaplacone($stanWP24(1)));
$po = $daneTransakcji();

wynik('wplata w P24: jedna weryfikacja', $r['atrapa']->ile(TransportOczekiwania::WERYFIKACJA) === 1);
wynik('wplata w P24: zamowienie oplacone', $statusZamowienia() === $config->verifiedStatus, $statusZamowienia());
wynik('wplata w P24: znacznik weryfikacji zapisany', (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') !== '');
wynik('wplata w P24: stan paid i adres podziekowania', ($r['odp']['state'] ?? '') === 'paid'
    && str_contains((string) ($r['odp']['redirect'] ?? ''), 'task=after_end')
    && str_contains((string) ($r['odp']['redirect'] ?? ''), 'order_id=' . $orderId), (string) $r['surowa']);
wynik('wplata w P24: wtyczka sama nie przekierowuje, robi to skrypt', $r['wtyczka']->przekierowanie === null);

// Zwykła droga: powiadomienie z P24 opłaciło zamówienie, zanim skrypt zapytał.
$r = $zapytaj($znacznik);
wynik('zamowienie juz oplacone: paid bez pytania P24', ($r['odp']['state'] ?? '') === 'paid' && count($r['atrapa']->wywolania) === 0);
$r = $zapytaj($znacznik, true, $zaplacone($stanWP24(2)));
wynik('zamowienie juz oplacone: paid bez pytania P24 takze na prosbe skryptu', ($r['odp']['state'] ?? '') === 'paid' && count($r['atrapa']->wywolania) === 0);

echo PHP_EOL . '6. Pytanie o wynik: sprawy dla strony powrotu' . PHP_EOL;

$odNowa();
$r = $zapytaj($znacznik, true, new TransportOczekiwania([
    TransportOczekiwania::STAN        => $stanWP24(1),
    TransportOczekiwania::WERYFIKACJA => [401, '{"error":"Incorrect authentication","code":401}'],
]));
wynik('wplata bez udanej weryfikacji: stan check i adres strony powrotu', ($r['odp']['state'] ?? '') === 'check' && str_contains((string) ($r['odp']['redirect'] ?? ''), 'p24_action=return'), (string) $r['surowa']);
wynik('wplata bez udanej weryfikacji: zamowienie nieoplacone, sprzedawca zawiadomiony', $statusZamowienia() === 'created' && count($r['wtyczka']->alerty) === 1);

$odNowa();
$r = $zapytaj($znacznik, true, $zaplacone($stanWP24(1, 999)));
wynik('wplata w innej kwocie: check, bez weryfikacji, zamowienie nieoplacone', ($r['odp']['state'] ?? '') === 'check'
    && $r['atrapa']->ile(TransportOczekiwania::WERYFIKACJA) === 0 && $statusZamowienia() === 'created');

$odNowa();
$ustawStatus('cancelled');
$r = $zapytaj($znacznik, true, $zaplacone($stanWP24(1)));
wynik('zamowienie anulowane: check, P24 nie jest pytane', ($r['odp']['state'] ?? '') === 'check' && count($r['atrapa']->wywolania) === 0);
$ustawStatus('created');

echo PHP_EOL . '7. Powiadomienie BLIK: bank odrzucil platnosc' . PHP_EOL;

$odNowa();
$brakSrodkow = ['error' => '61', 'message' => 'INSUFFICIENT_FUNDS', 'status' => 'DECLINED', 'trxRef' => 'REF123'];
$historiaPrzed = $wpisowHistorii();

$r  = $powiadom($powiadomienieBlik($brakSrodkow));
$po = $daneTransakcji();

wynik('poprawne powiadomienie jest przyjete', $r['wynik'] === 'OK', var_export($r['wynik'], true));
wynik('przyczyna zapisana przy zamowieniu', (string) ($po->{OrderPaymentData::BLIK_ERROR} ?? '') === BlikError::InsufficientFunds->value, (string) ($po->{OrderPaymentData::BLIK_ERROR} ?? ''));
wynik('zapisana razem z sesja, ktorej dotyczy', (string) ($po->{OrderPaymentData::BLIK_ERROR_SESSION} ?? '') === $sessionId);
wynik('status zamowienia bez zmian: zostaje do oplacenia', $statusZamowienia() === 'created', $statusZamowienia());
wynik('dane transakcji bez zmian', (string) ($po->{OrderPaymentData::SESSION_ID} ?? '') === $sessionId && (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') === '');

$historiaPoPierwszym = $wpisowHistorii();
$powiadom($powiadomienieBlik($brakSrodkow));
wynik('powtorzone powiadomienie niczego nie zapisuje drugi raz', $wpisowHistorii() === $historiaPoPierwszym, $historiaPrzed . ' / ' . $historiaPoPierwszym . ' / ' . $wpisowHistorii());

$r = $zapytaj($znacznik, true, $zaplacone($stanWP24(0)));
wynik('strona oczekiwania dostaje stan failed i adres strony powrotu', ($r['odp']['state'] ?? '') === 'failed' && str_contains((string) ($r['odp']['redirect'] ?? ''), 'p24_action=return'), (string) $r['surowa']);
wynik('przy znanym odrzuceniu P24 nie jest pytane', count($r['atrapa']->wywolania) === 0);

$r = $wroc($pomocnik->znacznikPowrotu($zamowienie), new TransportOczekiwania([TransportOczekiwania::STAN => $stanWP24(0)]));
wynik('strona powrotu podaje powod odrzucenia', is_string($r['wynik'])
    && str_contains($r['wynik'], 'hikashop_przelewy24_unpaid')
    && str_contains($r['wynik'], $tekst('BLIK_ERROR_INSUFFICIENT_FUNDS')));
wynik('zamiast ogolnika o niepotwierdzonej wplacie', is_string($r['wynik']) && !str_contains($r['wynik'], $tekst('RETURN_UNPAID')));
wynik('i daje przycisk ponowienia', is_string($r['wynik']) && str_contains($r['wynik'], 'hikashop_przelewy24_retry') && str_contains($r['wynik'], 'p24_action=retry'));

// Po ponowieniu zamówienie ma nową sesję. Stare odrzucenie nie może
// straszyć klienta, który właśnie płaci jeszcze raz.
$nowaSesja = SessionId::generate(999997);
$odNowa([
    OrderPaymentData::SESSION_ID         => $nowaSesja,
    OrderPaymentData::SESSIONS           => [$sessionId],
    OrderPaymentData::BLIK_ERROR         => BlikError::InsufficientFunds->value,
    OrderPaymentData::BLIK_ERROR_SESSION => $sessionId,
]);
$r = $zapytaj($znacznik);
wynik('odrzucenie wczesniejszej sesji nie dotyczy nowej proby', ($r['odp']['state'] ?? '') === 'waiting', (string) $r['surowa']);
$r = $wroc($pomocnik->znacznikPowrotu($zamowienie));
wynik('strona powrotu tez go nie pokazuje', is_string($r['wynik']) && !str_contains($r['wynik'], $tekst('BLIK_ERROR_INSUFFICIENT_FUNDS')) && str_contains($r['wynik'], $tekst('RETURN_UNPAID')));

// Spóźnione powiadomienie o wcześniejszej próbie jest przyjmowane, bo
// dotyczy sesji tego zamówienia, ale bieżącej próby nie przerywa.
$odNowa([OrderPaymentData::SESSION_ID => $nowaSesja, OrderPaymentData::SESSIONS => [$sessionId]]);
$r = $powiadom($powiadomienieBlik($brakSrodkow));
wynik('spoznione powiadomienie o wczesniejszej sesji jest przyjete', $r['wynik'] === 'OK');
$r = $zapytaj($znacznik);
wynik('ale biezaca proba dalej czeka', ($r['odp']['state'] ?? '') === 'waiting');

echo PHP_EOL . '8. Powiadomienie BLIK: ksztalt i podpis' . PHP_EOL;

$odNowa();
$r = $powiadom($powiadomienieBlik($brakSrodkow, wData: true));
wynik('pola w obiekcie data sa przyjmowane', $r['wynik'] === 'OK' && (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === BlikError::InsufficientFunds->value);

// Podpis obejmuje pole result razem z kolejnością kluczy.
$odNowa();
$r = $powiadom($powiadomienieBlik(['status' => 'DECLINED', 'message' => 'USER_TIMEOUT', 'error' => '70']));
wynik('inna kolejnosc kluczy w result, podpis liczony z otrzymanej', $r['wynik'] === 'OK' && (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === BlikError::UserTimeout->value);

$odNowa();
$przestawione = json_decode($powiadomienieBlik($brakSrodkow), true);
$przestawione['result'] = array_reverse($przestawione['result'], true);
$r = $powiadom(json_encode($przestawione));
wynik('klucze przestawione po podpisaniu, podpis liczony z kolejnosci z dokumentacji', $r['wynik'] === 'OK');

$odNowa();
$r = $powiadom($powiadomienieBlik(['error' => 'USER_DECLINED', 'message' => '', 'status' => 'DECLINED']));
wynik('nazwa zamiast numeru w polu error', (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === BlikError::UserDeclined->value);

$odNowa();
$r = $powiadom($powiadomienieBlik($brakSrodkow, crc: 'obcy-klucz-crc-0000'));
wynik('zly podpis: odrzucone, nic nie zapisane', $r['wynik'] === false && (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === '');

$odrzucone = implode(' ', preg_grep('/Powiadomienie BLIK odrzucone/u', $r['wtyczka']->dziennik));
$podpis    = (string) (json_decode($powiadomienieBlik($brakSrodkow, crc: 'obcy-klucz-crc-0000'), true)['sign'] ?? '');

// Formatu powiadomienia nie dało się obejrzeć przed wydaniem. Gdy podpis się
// nie zgadza, dziennik musi pokazać, co przyszło, ale bez samego podpisu.
wynik('zly podpis: dziennik pokazuje tresc powiadomienia', str_contains($odrzucone, 'INSUFFICIENT_FUNDS') && str_contains($odrzucone, $sessionId), $odrzucone);
wynik('zly podpis: w dzienniku nie ma wartosci podpisu', $podpis !== '' && !str_contains($odrzucone, $podpis));

$podmienione = json_decode($powiadomienieBlik($brakSrodkow), true);
$podmienione['result']['error'] = '0';
$r = $powiadom(json_encode($podmienione));
wynik('tresc zmieniona po podpisaniu: odrzucone', $r['wynik'] === false);

$r = $powiadom($powiadomienieBlik($brakSrodkow, SessionId::generate(123)));
wynik('sesja spoza zamowienia: odrzucone mimo poprawnego podpisu', $r['wynik'] === false && (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === '');

$r = $powiadom($powiadomienieBlik($brakSrodkow), 'zly-znacznik');
wynik('zly znacznik w adresie: odrzucone', $r['wynik'] === false && (string) ($daneTransakcji()->{OrderPaymentData::BLIK_ERROR} ?? '') === '');
$r = $powiadom($powiadomienieBlik($brakSrodkow), $znacznik);
wynik('znacznik pytania o wynik nie otwiera powiadomien', $r['wynik'] === false);

foreach (['' => 'pusta tresc', 'nie-json' => 'smieci', '{"orderId":1}' => 'brak pola result', '[]' => 'pusta lista'] as $tresc => $opis) {
    $r = $powiadom((string) $tresc);
    wynik($opis . ': odrzucone bez bledu krytycznego', $r['wynik'] === false);
}

echo PHP_EOL . '9. Powiadomienie BLIK o powodzeniu niczego nie potwierdza' . PHP_EOL;

$odNowa([OrderPaymentData::BLIK_ERROR => BlikError::BadPin->value, OrderPaymentData::BLIK_ERROR_SESSION => $sessionId]);
$r  = $powiadom($powiadomienieBlik(['error' => '0', 'message' => 'OK', 'status' => 'AUTHORIZED']));
$po = $daneTransakcji();

wynik('powiadomienie o powodzeniu jest przyjete', $r['wynik'] === 'OK');
// Zapłatę potwierdza wyłącznie transaction/verify po zwykłym powiadomieniu.
wynik('zamowienie NIE staje sie oplacone', $statusZamowienia() === 'created' && (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') === '', $statusZamowienia());
wynik('zadnego zadania do P24', count($r['wtyczka']->transport->wywolania) === 0);
wynik('wczesniejsze odrzucenie tej sesji przestaje obowiazywac', (string) ($po->{OrderPaymentData::BLIK_ERROR} ?? '') === '');
$r = $zapytaj($znacznik);
wynik('strona oczekiwania dalej czeka na wlasciwe potwierdzenie', ($r['odp']['state'] ?? '') === 'waiting');

echo PHP_EOL . '10. Przyczyny odrzucenia z powiadomienia' . PHP_EOL;

foreach ([
    ['61', '', BlikError::InsufficientFunds, 'numer 61'],
    ['', 'INSUFFICIENT_FUNDS', BlikError::InsufficientFunds, 'nazwa w komunikacie'],
    ['USER_TIMEOUT', '', BlikError::UserTimeout, 'USER_TIMEOUT to nie zwykly TIMEOUT'],
    ['AM_TIMEOUT', '', BlikError::Timeout, 'AM_TIMEOUT'],
    ['TIMEOUT', '', BlikError::Timeout, 'TIMEOUT'],
    ['ER_BAD_PIN', '', BlikError::BadPin, 'ER_BAD_PIN'],
    ['BAD_PIN', '', BlikError::BadPin, 'BAD_PIN z tabeli sandboksa'],
    ['USER_DECLINED', '', BlikError::UserDeclined, 'USER_DECLINED'],
    ['SEC_DECLINED', '', BlikError::IssuerDeclined, 'SEC_DECLINED'],
    ['LIMIT_EXCEEDED', '', BlikError::LimitExceeded, 'LIMIT_EXCEEDED'],
    ['ISS_OUTOFSERVICE', '', BlikError::SystemError, 'ISS_OUTOFSERVICE'],
    ['user_declined', '', BlikError::UserDeclined, 'male litery'],
    ['1', 'cos nieznanego', BlikError::GeneralError, 'nieznany numer i nieznany komunikat'],
    ['GENERAL_ERROR', '', BlikError::GeneralError, 'GENERAL_ERROR'],
] as [$blad, $komunikat, $oczekiwana, $opis]) {
    $otrzymana = BlikError::fromNotification($blad, $komunikat);

    wynik($opis, $otrzymana === $oczekiwana, $otrzymana->value);
}

$pl = parse_ini_file(__DIR__ . '/../plugin/language/pl-PL/plg_hikashoppayment_przelewy24.ini');
wynik('odrzucenie w aplikacji ma wlasny komunikat po polsku', isset($pl[BlikError::UserDeclined->languageKey()]));

$bezPola = false;

try {
    BlikNotification::fromRequestBody('{"orderId":1,"sessionId":"x","method":181,"result":{"error":"61"}}');
} catch (SignatureException) {
    $bezPola = true;
}

wynik('powiadomienie bez podpisu jest niekompletne', $bezPola);

echo PHP_EOL . '11. Strona oczekiwania' . PHP_EOL;

$adresSprawdzenia = 'https://haskap.test/index.php?p24_action=return&x=1';
$widok            = $nowaWtyczka()->widokOczekiwania($adresStanu, $adresSprawdzenia);

wynik('naglowek o potwierdzeniu w aplikacji banku', str_contains($widok, 'hikashop_przelewy24_blik_waiting') && str_contains($widok, $tekst('BLIK_WAITING')));
wynik('skrypt zna adres pytania o wynik', str_contains($widok, '<script') && str_contains($widok, 'p24_action=blik_status') && str_contains($widok, $znacznik));
wynik('skrypt prosi sklep o pytanie P24 osobnym parametrem', str_contains($widok, "'&p24_ask=1'"));
wynik('po uplywie czasu strona idzie na adres powrotu', str_contains($widok, 'p24_action=return'));
wynik('przycisk sprawdzenia dziala bez skryptu', (bool) preg_match('#<a[^>]+hikashop_przelewy24_blik_check[^>]+href="[^"]*p24_action=return#', $widok));
wynik('klient nie czyta juz, ze strone mozna zamknac i tyle', !str_contains($widok, 'Tę stronę możesz zamknąć.'));
wynik('strona nie przechodzi sama do bramki P24', !str_contains($widok, 'hikashopPrzelewy24Button'));

$bezAdresu = $nowaWtyczka()->widokOczekiwania('', '');
wynik('bez adresu pytania nie ma skryptu ani przycisku', !str_contains($bezAdresu, '<script') && !str_contains($bezAdresu, 'hikashop_przelewy24_blik_check') && str_contains($bezAdresu, 'hikashop_przelewy24_blik_waiting'));

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy > 0 ? 1 : 0);
