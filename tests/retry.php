<?php
/**
 * Test przycisku ponowienia zapłaty w zainstalowanej Joomli.
 *
 * Uruchomienie:
 *   php tests/retry.php
 *
 * Przycisk „Spróbuj zapłacić ponownie” prowadził kiedyś do kasy, a ta
 * jest po złożeniu zamówienia pusta. Teraz prowadzi do wtyczki, która
 * rejestruje transakcję dla TEGO SAMEGO zamówienia i przekierowuje na
 * stronę płatności. Działa bez płatnego HikaShopa.
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
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
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
 * Atrapa sieci. Wtyczka zadaje P24 trzy różne pytania, więc odpowiedź
 * dobieramy po adresie: stan transakcji, weryfikacja, rejestracja.
 */
final class TransportAtrapa implements TransportInterface
{
    public const STAN        = 'transaction/by/sessionId';
    public const WERYFIKACJA = 'transaction/verify';
    public const REJESTRACJA = 'transaction/register';

    /** Tak P24 odpowiada na pytanie o transakcję, za którą nikt nie zapłacił. */
    public const NIEOPLACONA = [404, '{"error":"Transaction not found","responseCode":0}'];

    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    /**
     * @param  array<string, array{0: int, 1: string}>  $poAdresie  odpowiedzi dla wybranych pytań;
     *                                                             pozostałe dostają $kodHttp i $odpowiedz
     */
    public function __construct(
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"token":"TOKENPONOWIENIA123"},"responseCode":0}',
        private array $poAdresie = [self::STAN => self::NIEOPLACONA]
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
 * Wtyczka z atrapą sieci i zapamiętanym przekierowaniem zamiast wyjścia.
 */
final class WtyczkaTestowa extends Przelewy24
{
    public ?TransportAtrapa $transport = null;

    public ?string $przekierowanie = null;

    /** @var list<mixed> wiadomości, które wtyczka chciała wysłać sprzedawcy */
    public array $alerty = [];

    /** @var list<string> wiersze, które wtyczka zapisała w dzienniku */
    public array $dziennik = [];

    /**
     * Fałsz w miejscu numeru zamówienia to prośba o samą wiadomość do
     * sprzedawcy. W teście jej nie wysyłamy, tylko odkładamy do policzenia.
     */
    public function modifyOrder(&$order_id, $order_status, $history = null, $email = null, $payment_params = null)
    {
        if ($order_id === false) {
            $this->alerty[] = $email;

            return;
        }

        parent::modifyOrder($order_id, $order_status, $history, $email, $payment_params);
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

    public function adresPonowienia($order): string
    {
        return $this->buildRetryUrl($order);
    }

    public function znacznik($order): string
    {
        return $this->retryToken($order);
    }

    public function adresPowrotu($order): string
    {
        return $this->buildReturnUrl($order);
    }

    public function znacznikPowrotu($order): string
    {
        return $this->returnToken($order);
    }

    /**
     * Strona przejścia do bramki, taka jak po złożeniu zamówienia.
     */
    public function widokPrzejscia(string $bramka, string $ponowienie): string
    {
        $this->p24_paywall_url = $bramka;
        $this->p24_retry_url   = $ponowienie;

        return $this->renderPage('end');
    }
}

// --- przygotowanie ---------------------------------------------------

Joomla\CMS\Factory::getApplication()->getLanguage()->load('plg_hikashoppayment_przelewy24', JPATH_ADMINISTRATOR);

$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();

$q = $pdo->query("SELECT payment_id, payment_params FROM {$prefix}hikashop_payment WHERE payment_type = 'przelewy24' AND payment_published = 1 LIMIT 1");
$metoda = $q->fetch(PDO::FETCH_ASSOC);

if (!$metoda) {
    fwrite(STDERR, 'Brak opublikowanej metody platnosci przelewy24 w HikaShopie.' . PHP_EOL);
    exit(2);
}

$config = Config::fromPaymentParams((object) (array) @unserialize($metoda['payment_params']));

if (!$config->isComplete()) {
    fwrite(STDERR, 'Metoda platnosci nie ma kompletu danych P24.' . PHP_EOL);
    exit(2);
}

$sessionId  = SessionId::generate(999998);
$orderToken = bin2hex(random_bytes(16));

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
    ':numer'     => 'P24RETRY' . random_int(1000, 9999),
    ':utworzone' => time(),
    ':platnosc'  => $metoda['payment_id'],
    ':token'     => $orderToken,
    ':parametry' => serialize((object) [
        OrderPaymentData::SESSION_ID => $sessionId,
        OrderPaymentData::AMOUNT     => 1234,
        OrderPaymentData::CURRENCY   => 'PLN',
        OrderPaymentData::ATTEMPTS   => 1,
    ]),
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

/**
 * Cofa zamówienie do stanu „złożone, nieopłacone, po jednej próbie”,
 * żeby każda sekcja zaczynała od tego samego.
 */
$odNowa = static function () use ($pdo, $prefix, $orderId, $sessionId): void {
    $pdo->prepare("UPDATE {$prefix}hikashop_order SET order_status = 'created', order_payment_params = :p WHERE order_id = :id")
        ->execute([
            ':id' => $orderId,
            ':p'  => serialize((object) [
                OrderPaymentData::SESSION_ID => $sessionId,
                OrderPaymentData::AMOUNT     => 1234,
                OrderPaymentData::CURRENCY   => 'PLN',
                OrderPaymentData::ATTEMPTS   => 1,
            ]),
        ]);
};

/**
 * Odpowiedź P24 na pytanie o stan transakcji tego zamówienia.
 *
 * @return array{0: int, 1: string}
 */
$stanWP24 = static function (int $status, int $kwota = 1234, string $waluta = 'PLN') use ($sessionId): array {
    return [200, json_encode([
        'data' => [
            'orderId'       => 4321987,
            'sessionId'     => $sessionId,
            'status'        => $status,
            'amount'        => $kwota,
            'currency'      => $waluta,
            'paymentMethod' => 154,
        ],
        'responseCode' => 0,
    ])];
};

$tekst = static fn (string $klucz): string => htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_' . $klucz), ENT_QUOTES, 'UTF-8');

/**
 * Wywołuje ponowienie tak, jak zrobi to przeglądarka klienta.
 *
 * @return array{wynik: mixed, wtyczka: WtyczkaTestowa, wywolan: int, atrapa: TransportAtrapa}
 */
$ponow = static function (string $znacznik, ?TransportAtrapa $atrapa = null) use ($orderId): array {
    $atrapa ??= new TransportAtrapa();

    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaTestowa($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
    $wtyczka->transport = $atrapa;

    $wejscie = Joomla\CMS\Factory::getApplication()->input;
    $wejscie->set('p24_action', 'retry');
    $wejscie->set('order_id', $orderId);
    $wejscie->set('p24_retry', $znacznik);

    $statusy = [];
    $wynik   = $wtyczka->onPaymentNotification($statusy);

    return ['wynik' => $wynik, 'wtyczka' => $wtyczka, 'wywolan' => count($atrapa->wywolania), 'atrapa' => $atrapa];
};

$zamowienie = (object) ['order_id' => $orderId, 'order_token' => $orderToken];
$dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
$pomocnik   = new WtyczkaTestowa($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
$znacznik   = $pomocnik->znacznik($zamowienie);

// --- testy -----------------------------------------------------------

echo PHP_EOL . '1. Adres przycisku' . PHP_EOL;

$adres = $pomocnik->adresPonowienia($zamowienie);

wynik('prowadzi do wtyczki, nie do pustej kasy', str_contains($adres, 'task=notify') && !str_contains($adres, 'task=step'));
wynik('wskazuje ponowienie i zamowienie', str_contains($adres, 'p24_action=retry') && str_contains($adres, 'order_id=' . $orderId));
// Bez tego parametru wtyczka systemowa HikaShopa oddaje wynik zadania notify
// bez szablonu witryny i klient widzi goły tekst na białym tle.
wynik('strona ponowienia wyswietla sie w szablonie witryny', str_contains($adres, 'skip_system_notification=1'));
wynik('niesie znacznik zamowienia', str_contains($adres, 'p24_retry=' . $znacznik));
wynik('nie zdradza order_token wprost przez znacznik', $znacznik !== $orderToken && $znacznik !== md5($orderToken));
wynik('inny znacznik dla innego zamowienia', $pomocnik->znacznik((object) ['order_id' => $orderId + 1, 'order_token' => $orderToken]) !== $znacznik);

echo PHP_EOL . '2. Zadania, ktore musza zostac odrzucone' . PHP_EOL;

$r = $ponow('');
wynik('pusty znacznik: bez rejestracji w P24', $r['wywolan'] === 0);
wynik('pusty znacznik: bez przekierowania', $r['wtyczka']->przekierowanie === null);
wynik('pusty znacznik: komunikat dla klienta', is_string($r['wynik']) && str_contains($r['wynik'], htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_INVALID'), ENT_QUOTES, 'UTF-8')));

$r = $ponow(md5($orderToken));
wynik('znacznik powiadomien P24 nie otwiera ponowienia', $r['wywolan'] === 0 && $r['wtyczka']->przekierowanie === null);

$ustawStatus($config->verifiedStatus);
$r = $ponow($znacznik);
wynik('oplacone zamowienie: bez rejestracji', $r['wywolan'] === 0 && $r['wtyczka']->przekierowanie === null);
wynik('oplacone zamowienie: informacja o zaplacie', is_string($r['wynik']) && str_contains($r['wynik'], htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ALREADY_PAID'), ENT_QUOTES, 'UTF-8')));
wynik('oplacone zamowienie: bez kolejnego przycisku', is_string($r['wynik']) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry'));

$ustawStatus('cancelled');
$r = $ponow($znacznik);
wynik('anulowane zamowienie: bez rejestracji', $r['wywolan'] === 0 && $r['wtyczka']->przekierowanie === null);
wynik('anulowane zamowienie: komunikat', is_string($r['wynik']) && str_contains($r['wynik'], htmlspecialchars(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_UNAVAILABLE'), ENT_QUOTES, 'UTF-8')));

$ustawStatus('created');

echo PHP_EOL . '3. P24 nie odpowiada' . PHP_EOL;

// Skoro P24 nie odpowiada na pytanie o poprzednią próbę, nie wiemy, czy nie
// została opłacona. Nowej transakcji wtedy nie zakładamy.
$r = $ponow($znacznik, new TransportAtrapa(500, '{"error":"blad","code":500}', []));
wynik('bez rejestracji, bo nie wiadomo, czy poprzednia proba nie jest oplacona', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);
wynik('sesja przy zamowieniu bez zmian', (string) ($daneTransakcji()->{OrderPaymentData::SESSION_ID} ?? '') === $sessionId);
wynik('bez przekierowania', $r['wtyczka']->przekierowanie === null);
wynik('klient znow widzi przycisk ponowienia', is_string($r['wynik']) && str_contains($r['wynik'], 'hikashop_przelewy24_retry'));
wynik('awaria P24 przy pytaniu o stan trafia do dziennika jako blad', count(preg_grep('/\[BŁĄD\].*by\/sessionId/u', $r['wtyczka']->dziennik)) === 1);

echo PHP_EOL . '4. Udane ponowienie' . PHP_EOL;

$przed = $daneTransakcji();
$r     = $ponow($znacznik);
$po    = $daneTransakcji();

wynik('najpierw pytanie o stan transakcji, potem rejestracja', $r['wywolan'] === 2
    && str_contains($r['atrapa']->wywolania[0]['adres'], TransportAtrapa::STAN)
    && str_contains($r['atrapa']->wywolania[1]['adres'], TransportAtrapa::REJESTRACJA), (string) $r['wywolan']);
wynik('pytanie o stan dotyczy sesji tego zamowienia', str_ends_with($r['atrapa']->wywolania[0]['adres'], '/' . $sessionId));
wynik('jedna rejestracja w P24', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 1);
wynik('bez weryfikacji, bo nie ma czego weryfikowac', $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 0);
wynik('brak wplaty w P24 nie jest bledem w dzienniku', preg_grep('/\[BŁĄD\]/u', $r['wtyczka']->dziennik) === [], implode(' / ', preg_grep('/\[BŁĄD\]/u', $r['wtyczka']->dziennik)));

$tresc = json_decode((string) ($r['atrapa']->pytania(TransportAtrapa::REJESTRACJA)[0]['tresc'] ?? ''), true) ?: [];

// Po nieudanej płatności P24 nie pozwala dokończyć tej samej transakcji:
// jej strona od razu odsyła klienta do sklepu (sandbox, 09.10.2026).
// Ponowienie musi więc założyć nową.
$nowaSesja = (string) ($tresc['sessionId'] ?? '');

wynik('nowa sesja, inna niz przy poprzedniej probie', $nowaSesja !== $sessionId && SessionId::isValid($nowaSesja), $nowaSesja);
wynik('nowa sesja nadal wskazuje zamowienie', str_starts_with($nowaSesja, 'hika_' . $orderId . '_'), $nowaSesja);
wynik('kwota zamowienia w groszach', ($tresc['amount'] ?? 0) === 1234, (string) ($tresc['amount'] ?? ''));
wynik('adres powrotu prowadzi do wtyczki, nie wprost do podziekowania', str_contains((string) ($tresc['urlReturn'] ?? ''), 'p24_action=return') && !str_contains((string) ($tresc['urlReturn'] ?? ''), 'task=after_end'), (string) ($tresc['urlReturn'] ?? ''));
wynik('przekierowanie na strone platnosci', str_contains((string) $r['wtyczka']->przekierowanie, 'TOKENPONOWIENIA123'), (string) $r['wtyczka']->przekierowanie);
wynik('nowa sesja zapisana przy zamowieniu jako biezaca', (string) ($po->{OrderPaymentData::SESSION_ID} ?? '') === $nowaSesja);
wynik('poprzednia sesja zostaje na liscie wczesniejszych', array_values((array) ($po->{OrderPaymentData::SESSIONS} ?? [])) === [$sessionId]);
wynik('licznik prob wzrosl', (int) ($po->{OrderPaymentData::ATTEMPTS} ?? 0) > (int) ($przed->{OrderPaymentData::ATTEMPTS} ?? 0));
wynik('token zapisany przy zamowieniu', (string) ($po->{OrderPaymentData::TOKEN} ?? '') === 'TOKENPONOWIENIA123');

$odNowa();
$r = $ponow($znacznik, new TransportAtrapa(poAdresie: [TransportAtrapa::STAN => $stanWP24(0)]));
wynik('transakcja zarejestrowana, ale bez wplaty: zwykla rejestracja', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 1 && str_contains((string) $r['wtyczka']->przekierowanie, 'TOKENPONOWIENIA123'));

// Powiadomienie o zapłacie potrafi nie dotrzeć. Sklep ma wtedy zamówienie
// nieopłacone i przycisk „Zapłać teraz”, a pieniądze są już w P24.
echo PHP_EOL . '5. Wplata jest w P24, sklep o niej nie wie' . PHP_EOL;

$zaplacone = static fn (array $stan): TransportAtrapa => new TransportAtrapa(poAdresie: [
    TransportAtrapa::STAN        => $stan,
    TransportAtrapa::WERYFIKACJA => [200, '{"data":{"status":"success"},"responseCode":0}'],
]);

foreach ([1 => 'czeka na weryfikacje', 2 => 'zweryfikowana'] as $stan => $opis) {
    $odNowa();
    $r  = $ponow($znacznik, $zaplacone($stanWP24($stan)));
    $po = $daneTransakcji();

    wynik("wplata $opis: bez nowej rejestracji", $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);
    wynik("wplata $opis: klient nie trafia do bramki", $r['wtyczka']->przekierowanie === null);
    wynik("wplata $opis: jedna weryfikacja", $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 1);
    wynik("wplata $opis: zamowienie oplacone", $statusZamowienia() === $config->verifiedStatus, $statusZamowienia());
    wynik("wplata $opis: znacznik weryfikacji zapisany", (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') !== '');
}

$weryfikacja = json_decode((string) ($r['atrapa']->pytania(TransportAtrapa::WERYFIKACJA)[0]['tresc'] ?? ''), true) ?: [];

wynik('weryfikacja: sesja zamowienia', ($weryfikacja['sessionId'] ?? '') === $sessionId);
wynik('weryfikacja: numer transakcji z P24', ($weryfikacja['orderId'] ?? 0) === 4321987);
wynik('weryfikacja: kwota zamowienia, nie kwota z odpowiedzi', ($weryfikacja['amount'] ?? 0) === 1234 && ($weryfikacja['currency'] ?? '') === 'PLN');
wynik('numer transakcji P24 zapisany przy zamowieniu', (int) ($po->{OrderPaymentData::P24_ORDER_ID} ?? 0) === 4321987);
wynik('metoda platnosci zapisana przy zamowieniu', (int) ($po->{OrderPaymentData::METHOD_ID} ?? 0) === 154);
wynik('klient czyta, ze zaplata jest potwierdzona', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_CONFIRMED')) && str_contains($r['wynik'], 'hikashop_przelewy24_paid'));
wynik('bez naglowka o nieudanej platnosci', is_string($r['wynik']) && !str_contains($r['wynik'], $tekst('PAYMENT_NOT_STARTED')));
wynik('bez przycisku platnosci i ponowienia', is_string($r['wynik']) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry') && !str_contains($r['wynik'], 'hikashopPrzelewy24Button'));
wynik('sprzedawca nie dostaje wiadomosci o awarii', $r['wtyczka']->alerty === []);

$r = $ponow($znacznik, $zaplacone($stanWP24(2)));
wynik('kolejne klikniecie: P24 nie jest juz pytane', $r['wywolan'] === 0, (string) $r['wywolan']);
wynik('kolejne klikniecie: informacja o zaplacie', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('ALREADY_PAID')));

echo PHP_EOL . '6. Wplata jest w P24, ale weryfikacja sie nie udaje' . PHP_EOL;

$odNowa();
$odmowa = static fn (): TransportAtrapa => new TransportAtrapa(poAdresie: [
    TransportAtrapa::STAN        => $stanWP24(1),
    TransportAtrapa::WERYFIKACJA => [401, '{"error":"Incorrect authentication","code":401}'],
]);

$r  = $ponow($znacznik, $odmowa());
$po = $daneTransakcji();

wynik('bez nowej rejestracji: klient juz zaplacil', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0 && $r['wtyczka']->przekierowanie === null);
wynik('zamowienie zostaje nieoplacone', $statusZamowienia() === 'created', $statusZamowienia());
wynik('bez znacznika weryfikacji', (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') === '');
wynik('klient czyta, ze wplata jest odnotowana', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_PENDING')));
wynik('bez przycisku platnosci i ponowienia', is_string($r['wynik']) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry') && !str_contains($r['wynik'], 'hikashopPrzelewy24Button'));
wynik('sprzedawca dostaje jedna wiadomosc', count($r['wtyczka']->alerty) === 1, count($r['wtyczka']->alerty) . ' wiadomosci');

$r = $ponow($znacznik, $odmowa());
wynik('drugie klikniecie: weryfikacja ponowiona', $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 1);
wynik('drugie klikniecie: bez drugiej wiadomosci', $r['wtyczka']->alerty === [], count($r['wtyczka']->alerty) . ' wiadomosci');

$odNowa();
$r = $ponow($znacznik, new TransportAtrapa(poAdresie: [
    TransportAtrapa::STAN        => $stanWP24(1),
    TransportAtrapa::WERYFIKACJA => [200, '{"data":{"status":"rejected"},"responseCode":0}'],
]));
wynik('weryfikacja bez potwierdzenia: zamowienie nieoplacone, bez bramki', $statusZamowienia() === 'created' && $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);
wynik('weryfikacja bez potwierdzenia: klient czyta, ze wplata jest odnotowana', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_PENDING')));

echo PHP_EOL . '7. Wplata w P24 nie pasuje do zamowienia' . PHP_EOL;

foreach (['inna kwota' => $stanWP24(1, 9999), 'inna waluta' => $stanWP24(1, 1234, 'EUR')] as $opis => $stan) {
    $odNowa();
    $r = $ponow($znacznik, $zaplacone($stan));

    wynik("$opis: bez weryfikacji i bez rejestracji", $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 0 && $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);
    wynik("$opis: zamowienie zostaje nieoplacone", $statusZamowienia() === 'created', $statusZamowienia());
    wynik("$opis: klient ma sie skontaktowac ze sklepem", is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_MISMATCH')) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry'));
}

$odNowa();
$r = $ponow($znacznik, $zaplacone($stanWP24(3)));
wynik('wplata zwrocona w P24: bez weryfikacji i bez rejestracji', $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 0 && $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);
wynik('wplata zwrocona w P24: zamowienie zostaje nieoplacone', $statusZamowienia() === 'created');
wynik('wplata zwrocona w P24: komunikat bez przycisku', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('RETRY_UNAVAILABLE')) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry'));

echo PHP_EOL . '8. Kolejne proby i wplata na wczesniejsza sesje' . PHP_EOL;

$odNowa();
$r1 = $ponow($znacznik);
$s1 = (string) ($daneTransakcji()->{OrderPaymentData::SESSION_ID} ?? '');
$r2 = $ponow($znacznik);
$po = $daneTransakcji();
$s2 = (string) ($po->{OrderPaymentData::SESSION_ID} ?? '');

wynik('kazda proba dostaje wlasna sesje', count(array_unique([$sessionId, $s1, $s2])) === 3 && $s1 !== '' && $s2 !== '');
wynik('przed kolejna proba pytanie o kazda wczesniejsza sesje', $r2['atrapa']->ile(TransportAtrapa::STAN) === 2, (string) $r2['atrapa']->ile(TransportAtrapa::STAN));
wynik('pytania ida od najnowszej sesji', str_ends_with($r2['atrapa']->pytania(TransportAtrapa::STAN)[0]['adres'] ?? '', '/' . $s1)
    && str_ends_with($r2['atrapa']->pytania(TransportAtrapa::STAN)[1]['adres'] ?? '', '/' . $sessionId));
wynik('lista wczesniejszych sesji w kolejnosci prob', array_values((array) ($po->{OrderPaymentData::SESSIONS} ?? [])) === [$sessionId, $s1]);
wynik('licznik prob liczy kazda', (int) ($po->{OrderPaymentData::ATTEMPTS} ?? 0) === 3, (string) ($po->{OrderPaymentData::ATTEMPTS} ?? ''));

// Klient próbował trzy razy, a wpłata doszła na pierwszą sesję, na przykład
// przelewem tradycyjnym. Czwarta próba nie może założyć kolejnej transakcji.
$wplataNaPierwsza = new TransportAtrapa(poAdresie: [
    'by/sessionId/' . $sessionId => $stanWP24(1),
    TransportAtrapa::STAN        => TransportAtrapa::NIEOPLACONA,
    TransportAtrapa::WERYFIKACJA => [200, '{"data":{"status":"success"},"responseCode":0}'],
]);

$r  = $ponow($znacznik, $wplataNaPierwsza);
$po = $daneTransakcji();

$weryfikacja = json_decode((string) ($r['atrapa']->pytania(TransportAtrapa::WERYFIKACJA)[0]['tresc'] ?? ''), true) ?: [];

wynik('wplata na wczesniejsza sesje: bez nowej rejestracji', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0 && $r['wtyczka']->przekierowanie === null);
wynik('wplata na wczesniejsza sesje: weryfikacja tej sesji', ($weryfikacja['sessionId'] ?? '') === $sessionId, (string) ($weryfikacja['sessionId'] ?? ''));
wynik('wplata na wczesniejsza sesje: zamowienie oplacone', $statusZamowienia() === $config->verifiedStatus, $statusZamowienia());
wynik('wplata na wczesniejsza sesje: zapisana oplacona sesja', (string) ($po->{OrderPaymentData::PAID_SESSION} ?? '') === $sessionId);
wynik('wplata na wczesniejsza sesje: klient czyta, ze zaplata jest potwierdzona', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_CONFIRMED')));

// Lista wcześniejszych sesji nie rośnie bez końca.
$odNowa();

for ($i = 0; $i < OrderPaymentData::SESSIONS_LIMIT + 3; $i++) {
    $ponow($znacznik);
}

$po = $daneTransakcji();
wynik('lista wczesniejszych sesji ma limit', count((array) ($po->{OrderPaymentData::SESSIONS} ?? [])) === OrderPaymentData::SESSIONS_LIMIT, (string) count((array) ($po->{OrderPaymentData::SESSIONS} ?? [])));
wynik('z listy odpadaja najstarsze sesje', !in_array($sessionId, (array) ($po->{OrderPaymentData::SESSIONS} ?? []), true));

echo PHP_EOL . '9. Przycisk na stronie przejscia do bramki' . PHP_EOL;

// Klient klika go po powrocie z bramki przyciskiem „wstecz”. Zapisany na
// stronie adres bramki jest wtedy martwy, więc przycisk idzie przez sklep.
$widok = $pomocnik->widokPrzejscia('https://sandbox.przelewy24.pl/trnRequest/TOKENSTRONY', $adres);

wynik('przycisk prowadzi przez sklep, ktory zalozy nowa transakcje', preg_match('/id="hikashopPrzelewy24Button"[^>]*href="[^"]*p24_action=retry/s', $widok) === 1);
wynik('przycisk nie prowadzi wprost do zapisanego adresu bramki', preg_match('/id="hikashopPrzelewy24Button"[^>]*href="[^"]*trnRequest/s', $widok) === 0);
wynik('samoczynne przejscie nadal idzie wprost do bramki', str_contains($widok, 'var adres = "https://sandbox.przelewy24.pl/trnRequest/TOKENSTRONY"'));
wynik('bez adresu ponowienia przycisk prowadzi do bramki', preg_match('/id="hikashopPrzelewy24Button"[^>]*href="[^"]*trnRequest/s', $pomocnik->widokPrzejscia('https://sandbox.przelewy24.pl/trnRequest/TOKENSTRONY', '')) === 1);

// P24 odsyła klienta na ten sam adres po zapłacie, po błędzie i po
// rezygnacji, a po nieudanej płatności nie pokazuje nawet komunikatu.
echo PHP_EOL . '10. Powrot klienta ze strony platnosci P24' . PHP_EOL;

/**
 * Wywołuje powrót z bramki tak, jak zrobi to przeglądarka klienta.
 *
 * @return array{wynik: mixed, wtyczka: WtyczkaTestowa, wywolan: int, atrapa: TransportAtrapa}
 */
$wroc = static function (string $znacznikWAdresie, ?TransportAtrapa $atrapa = null) use ($orderId): array {
    $atrapa ??= new TransportAtrapa();

    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaTestowa($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
    $wtyczka->transport = $atrapa;

    $wejscie = Joomla\CMS\Factory::getApplication()->input;
    $wejscie->set('p24_action', 'return');
    $wejscie->set('order_id', $orderId);
    $wejscie->set('p24_return', $znacznikWAdresie);

    $statusy = [];
    $wynik   = $wtyczka->onPaymentNotification($statusy);

    return ['wynik' => $wynik, 'wtyczka' => $wtyczka, 'wywolan' => count($atrapa->wywolania), 'atrapa' => $atrapa];
};

$znacznikPowrotu = $pomocnik->znacznikPowrotu($zamowienie);
$adresPowrotu    = $pomocnik->adresPowrotu($zamowienie);

wynik('adres powrotu prowadzi do wtyczki', str_contains($adresPowrotu, 'task=notify') && str_contains($adresPowrotu, 'p24_action=return') && str_contains($adresPowrotu, 'order_id=' . $orderId));
wynik('adres powrotu nie prowadzi wprost do podziekowania', !str_contains($adresPowrotu, 'task=after_end'));
wynik('strona powrotu wyswietla sie w szablonie witryny', str_contains($adresPowrotu, 'skip_system_notification=1'));
wynik('adres powrotu niesie wlasny znacznik', str_contains($adresPowrotu, 'p24_return=' . $znacznikPowrotu)
    && $znacznikPowrotu !== $znacznik && $znacznikPowrotu !== md5($orderToken) && $znacznikPowrotu !== $orderToken);

$odNowa();
$r = $wroc('');
wynik('pusty znacznik: P24 nie jest pytane, bez przekierowania', $r['wywolan'] === 0 && $r['wtyczka']->przekierowanie === null);
$r = $wroc($znacznik);
wynik('znacznik ponowienia nie otwiera powrotu', $r['wywolan'] === 0 && $r['wtyczka']->przekierowanie === null);

// Nieudana płatność: P24 zna transakcję, ale wpłaty nie ma.
$r = $wroc($znacznikPowrotu, new TransportAtrapa(poAdresie: [TransportAtrapa::STAN => $stanWP24(0)]));

wynik('nieudana platnosc: klient NIE trafia na podziekowanie', $r['wtyczka']->przekierowanie === null, (string) $r['wtyczka']->przekierowanie);
wynik('nieudana platnosc: czyta, ze platnosc nie zostala potwierdzona', is_string($r['wynik'])
    && str_contains($r['wynik'], 'hikashop_przelewy24_unpaid')
    && str_contains($r['wynik'], $tekst('RETURN_UNPAID_TITLE'))
    && str_contains($r['wynik'], $tekst('RETURN_UNPAID')));
wynik('nieudana platnosc: przycisk ponowienia', is_string($r['wynik']) && str_contains($r['wynik'], 'hikashop_przelewy24_retry') && str_contains($r['wynik'], 'p24_action=retry'));
wynik('nieudana platnosc: bez naglowka o nierozpoczetej platnosci i bez podziekowania', is_string($r['wynik']) && !str_contains($r['wynik'], $tekst('PAYMENT_NOT_STARTED')) && !str_contains($r['wynik'], 'hikashop_przelewy24_paid'));
wynik('nieudana platnosc: zamowienie zostaje nieoplacone', $statusZamowienia() === 'created', $statusZamowienia());
wynik('nieudana platnosc: sam powrot niczego w P24 nie rejestruje ani nie weryfikuje', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0 && $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 0);
wynik('nieudana platnosc: sesja zamowienia bez zmian', (string) ($daneTransakcji()->{OrderPaymentData::SESSION_ID} ?? '') === $sessionId);

// Klient zrezygnował, zanim wybrał sposób zapłaty: P24 nie zna transakcji.
$r = $wroc($znacznikPowrotu);
wynik('rezygnacja przed wyborem metody: ta sama strona, bez podziekowania', $r['wtyczka']->przekierowanie === null && is_string($r['wynik']) && str_contains($r['wynik'], $tekst('RETURN_UNPAID_TITLE')));

// Udana płatność: wpłata jest w P24, a powiadomienie mogło jeszcze nie dojść.
$r  = $wroc($znacznikPowrotu, $zaplacone($stanWP24(1)));
$po = $daneTransakcji();

wynik('udana platnosc: weryfikacja bez czekania na powiadomienie', $r['atrapa']->ile(TransportAtrapa::WERYFIKACJA) === 1);
wynik('udana platnosc: zamowienie oplacone', $statusZamowienia() === $config->verifiedStatus, $statusZamowienia());
wynik('udana platnosc: znacznik weryfikacji zapisany', (string) ($po->{OrderPaymentData::VERIFIED_AT} ?? '') !== '');
wynik('udana platnosc: dopiero teraz podziekowanie', str_contains((string) $r['wtyczka']->przekierowanie, 'task=after_end') && str_contains((string) $r['wtyczka']->przekierowanie, 'order_id=' . $orderId), (string) $r['wtyczka']->przekierowanie);
wynik('udana platnosc: bez nowej rejestracji', $r['atrapa']->ile(TransportAtrapa::REJESTRACJA) === 0);

// Powiadomienie zdążyło przed klientem albo klient odświeżył adres powrotu.
$r = $wroc($znacznikPowrotu, $zaplacone($stanWP24(2)));
wynik('zamowienie juz oplacone: bez pytan do P24, od razu podziekowanie', $r['wywolan'] === 0 && str_contains((string) $r['wtyczka']->przekierowanie, 'task=after_end'), (string) $r['wywolan']);

// P24 nie odpowiada: nie wiemy, jak było, więc nie zgadujemy w żadną stronę.
$odNowa();
$r = $wroc($znacznikPowrotu, new TransportAtrapa(500, '{"error":"blad","code":500}', []));
wynik('P24 nie odpowiada: bez podziekowania i bez zmiany statusu', $r['wtyczka']->przekierowanie === null && $statusZamowienia() === 'created');
wynik('P24 nie odpowiada: neutralna informacja', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('RETURN_UNKNOWN')));
wynik('P24 nie odpowiada: przycisk ponowienia zostaje', is_string($r['wynik']) && str_contains($r['wynik'], 'hikashop_przelewy24_retry'));

// Wpłata jest, ale weryfikacja się nie udała: klient nie może płacić drugi raz.
$odNowa();
$r = $wroc($znacznikPowrotu, $odmowa());
wynik('wplata bez udanej weryfikacji: informacja, ze jest odnotowana', is_string($r['wynik']) && str_contains($r['wynik'], $tekst('PAID_PENDING')));
wynik('wplata bez udanej weryfikacji: bez przycisku ponowienia i bez podziekowania', is_string($r['wynik']) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry') && $r['wtyczka']->przekierowanie === null);
wynik('wplata bez udanej weryfikacji: zamowienie zostaje nieoplacone, sprzedawca zawiadomiony', $statusZamowienia() === 'created' && count($r['wtyczka']->alerty) === 1);

// Zamówienie anulowane w międzyczasie: nie ma czego ponawiać.
$odNowa();
$ustawStatus('cancelled');
$r = $wroc($znacznikPowrotu, new TransportAtrapa(poAdresie: [TransportAtrapa::STAN => $stanWP24(0)]));
wynik('anulowane zamowienie: bez przycisku ponowienia', is_string($r['wynik']) && !str_contains($r['wynik'], 'hikashop_przelewy24_retry') && $r['wtyczka']->przekierowanie === null);
$ustawStatus('created');

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy > 0 ? 1 : 0);
