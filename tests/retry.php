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

final class TransportAtrapa implements TransportInterface
{
    /** @var list<array{metoda: string, adres: string, tresc: mixed}> */
    public array $wywolania = [];

    public function __construct(
        private int $kodHttp = 200,
        private string $odpowiedz = '{"data":{"token":"TOKENPONOWIENIA123"},"responseCode":0}'
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
 * Wtyczka z atrapą sieci i zapamiętanym przekierowaniem zamiast wyjścia.
 */
final class WtyczkaTestowa extends Przelewy24
{
    public ?TransportAtrapa $transport = null;

    public ?string $przekierowanie = null;

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

/**
 * Wywołuje ponowienie tak, jak zrobi to przeglądarka klienta.
 *
 * @return array{wynik: mixed, wtyczka: WtyczkaTestowa, wywolan: int}
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

    return ['wynik' => $wynik, 'wtyczka' => $wtyczka, 'wywolan' => count($atrapa->wywolania)];
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

$r = $ponow($znacznik, new TransportAtrapa(500, '{"error":"blad","code":500}'));
wynik('proba rejestracji byla', $r['wywolan'] === 1);
wynik('bez przekierowania', $r['wtyczka']->przekierowanie === null);
wynik('klient znow widzi przycisk ponowienia', is_string($r['wynik']) && str_contains($r['wynik'], 'hikashop_przelewy24_retry'));

echo PHP_EOL . '4. Udane ponowienie' . PHP_EOL;

$przed = $daneTransakcji();
$r     = $ponow($znacznik);
$po    = $daneTransakcji();

wynik('jedna rejestracja w P24', $r['wywolan'] === 1, (string) $r['wywolan']);

$tresc = json_decode((string) ($r['wtyczka']->transport->wywolania[0]['tresc'] ?? ''), true) ?: [];

wynik('ten sam identyfikator sesji co przy zamowieniu', ($tresc['sessionId'] ?? '') === $sessionId);
wynik('kwota zamowienia w groszach', ($tresc['amount'] ?? 0) === 1234, (string) ($tresc['amount'] ?? ''));
wynik('przekierowanie na strone platnosci', str_contains((string) $r['wtyczka']->przekierowanie, 'TOKENPONOWIENIA123'), (string) $r['wtyczka']->przekierowanie);
wynik('sesja przy zamowieniu bez zmian', (string) ($po->{OrderPaymentData::SESSION_ID} ?? '') === $sessionId);
wynik('licznik prob wzrosl', (int) ($po->{OrderPaymentData::ATTEMPTS} ?? 0) > (int) ($przed->{OrderPaymentData::ATTEMPTS} ?? 0));
wynik('token zapisany przy zamowieniu', (string) ($po->{OrderPaymentData::TOKEN} ?? '') === 'TOKENPONOWIENIA123');

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy > 0 ? 1 : 0);
