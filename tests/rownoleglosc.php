<?php
/**
 * Testy blokady zamowienia przy rownoleglych zadaniach.
 *
 * Uruchomienie:
 *   php tests/rownoleglosc.php
 *
 * Powiadomienie z P24 i klient wracajacy z bramki trafiaja do sklepu
 * zwykle w tej samej sekundzie. Do 1.0.10 oba zadania potrafily naraz
 * uznac, ze zaplata nie jest jeszcze potwierdzona, i oba zmienialy status.
 *
 * Test uruchamia osobne procesy PHP, bo tylko tak powstaje prawdziwy
 * wyscig o te sama baze. W procesach potomnych odczyt zamowienia jest
 * celowo spowolniony, zeby okno wyscigu bylo szerokie i wynik nie zalezal
 * od szczescia. Siec nie jest potrzebna: potwierdzenie zaplaty idzie tu
 * prosto przez markVerified(), czyli to, co nastepuje po udanym verify.
 *
 * Zamowienie testowe powstaje bez pozycji i jest kasowane na koncu.
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderLock;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;

/**
 * Wtyczka, ktora potwierdza zaplate w procesie potomnym.
 */
final class WtyczkaWyscigu extends Przelewy24
{
    /** Ile razy wtyczka naprawde zmienila status zamowienia. */
    public int $zmianStatusu = 0;

    public function getOrder($order_id)
    {
        $zamowienie = parent::getOrder($order_id);

        // Poszerza okno miedzy sprawdzeniem znacznika weryfikacji a zapisem.
        usleep(300000);

        return $zamowienie;
    }

    public function modifyOrder(&$order_id, $order_status, $history = null, $email = null, $payment_params = null)
    {
        if ($order_id !== false) {
            $this->zmianStatusu++;
        }

        parent::modifyOrder($order_id, $order_status, $history, $email, $payment_params);
    }

    public function potwierdz(int $orderId, Config $config): bool
    {
        $logger = new Logger($this->name, false, $config->secrets(), static function (): void {});

        return $this->markVerified($orderId, $config, $logger);
    }
}

// --- proces potomny --------------------------------------------------

$tryb = $argv[1] ?? '';

if ($tryb === '--dziecko=potwierdz') {
    $orderId = (int) $argv[2];

    // Oba procesy ruszaja w tej samej chwili, niezaleznie od czasu rozruchu.
    time_sleep_until((float) $argv[3]);

    $dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
    $wtyczka    = new WtyczkaWyscigu($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
    $wynik      = $wtyczka->potwierdz($orderId, metodaPlatnosciP24()['config']);

    echo PHP_EOL . 'WYNIK ' . json_encode(['oplacone' => $wynik, 'zmian' => $wtyczka->zmianStatusu]) . PHP_EOL;

    exit(0);
}

if ($tryb === '--dziecko=zapisz') {
    $orderId = (int) $argv[2];

    echo PHP_EOL . 'START ' . microtime(true) . PHP_EOL;
    flush();

    $zapisano = OrderPaymentData::store($orderId, ['p24_test_wyscigu' => 'zapisane']);

    echo PHP_EOL . 'WYNIK ' . json_encode(['zapisano' => $zapisano, 'koniec' => microtime(true)]) . PHP_EOL;

    exit(0);
}

// --- proces glowny ---------------------------------------------------

$zdane = 0;
$bledy = 0;

function wynik(string $opis, bool $ok, string $szczegol = ''): void
{
    global $zdane, $bledy;

    if ($ok) {
        $zdane++;
        echo '  OK   ' . $opis . PHP_EOL;

        return;
    }

    $bledy++;
    echo '  BLAD ' . $opis . ($szczegol !== '' ? ' (' . $szczegol . ')' : '') . PHP_EOL;
}

/**
 * Uruchamia ten plik jako proces potomny.
 *
 * @return array{proces: resource, wyjscie: resource}
 */
function dziecko(string ...$argumenty): array
{
    // Ścieżka względna, jak przy ręcznym uruchomieniu: z bezwzględnej ścieżki
    // Windows Joomla nie umie zbudować adresu bieżącego żądania.
    $proces = proc_open(
        array_merge([PHP_BINARY, 'tests/' . basename(__FILE__)], $argumenty),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $rury,
        dirname(__DIR__)
    );

    if (!is_resource($proces)) {
        fwrite(STDERR, 'Nie udalo sie uruchomic procesu potomnego.' . PHP_EOL);
        exit(2);
    }

    fclose($rury[0]);

    return ['proces' => $proces, 'wyjscie' => $rury[1]];
}

/**
 * Czeka na koniec procesu potomnego i zwraca jego wynik.
 *
 * @return array<string, mixed>
 */
function wynikDziecka(array $dziecko): array
{
    $tekst = (string) stream_get_contents($dziecko['wyjscie']);
    fclose($dziecko['wyjscie']);
    proc_close($dziecko['proces']);

    if (preg_match('/^WYNIK (\{.*\})\r?$/m', $tekst, $trafienie) !== 1) {
        return ['blad' => trim($tekst)];
    }

    return (array) json_decode($trafienie[1], true);
}

$metoda = metodaPlatnosciP24();
$config = $metoda['config'];
$pdo    = polaczenieZBaza();
$prefix = przedrostekTabel();

echo 'Metoda platnosci: id=' . $metoda['id'] . ', srodowisko ' . $config->environment->value . PHP_EOL;

$orderToken = bin2hex(random_bytes(16));

$pdo->prepare("
    INSERT INTO {$prefix}hikashop_order
        (order_number, order_created, order_modified, order_status, order_type,
         order_full_price, order_currency_id, order_payment_id, order_payment_method,
         order_token, order_payment_params)
    VALUES
        (:numer, :utworzone, :utworzone, 'created', 'sale',
         11.50, 125, :platnosc, 'przelewy24',
         :token, :parametry)
")->execute([
    ':numer'     => 'P24TEST' . random_int(1000, 9999),
    ':utworzone' => time(),
    ':platnosc'  => $metoda['id'],
    ':token'     => $orderToken,
    ':parametry' => serialize((object) [
        OrderPaymentData::SESSION_ID => SessionId::generate(999999),
        OrderPaymentData::AMOUNT     => 1150,
        OrderPaymentData::CURRENCY   => 'PLN',
        OrderPaymentData::ATTEMPTS   => 1,
    ]),
]);

$orderId = (int) $pdo->lastInsertId();

echo 'Zamowienie testowe: ' . $orderId . PHP_EOL;

register_shutdown_function(static function () use ($pdo, $prefix, $orderId): void {
    $pdo->prepare("DELETE FROM {$prefix}hikashop_order WHERE order_id = :id")->execute([':id' => $orderId]);
    $pdo->prepare("DELETE FROM {$prefix}hikashop_history WHERE history_order_id = :id")->execute([':id' => $orderId]);
    echo 'Zamowienie testowe ' . $orderId . ' skasowane.' . PHP_EOL;
});

echo PHP_EOL . '1. Nazwa blokady' . PHP_EOL;

$nazwa = OrderLock::name($orderId);
wynik('blokada ma nazwe zalezna od zamowienia', $nazwa !== '' && str_ends_with($nazwa, '_' . $orderId), $nazwa);
wynik('nazwa odroznia witryny przedrostkiem tabel', str_contains($nazwa, $prefix), $nazwa);
wynik('nazwa miesci sie w limicie MySQL (64 znaki)', strlen($nazwa) <= 64, (string) strlen($nazwa));

echo PHP_EOL . '2. Dwa zadania naraz potwierdzaja te sama zaplate' . PHP_EOL;

$start   = (string) (microtime(true) + 4);
$pierwsze = dziecko('--dziecko=potwierdz', (string) $orderId, $start);
$drugie   = dziecko('--dziecko=potwierdz', (string) $orderId, $start);
$wyniki   = [wynikDziecka($pierwsze), wynikDziecka($drugie)];

$opis = json_encode($wyniki, JSON_UNESCAPED_UNICODE);

wynik('oba procesy skonczyly prace', !isset($wyniki[0]['blad']) && !isset($wyniki[1]['blad']), $opis);
wynik('oba widza zamowienie jako oplacone', ($wyniki[0]['oplacone'] ?? null) === true && ($wyniki[1]['oplacone'] ?? null) === true, $opis);
wynik('status zmienil tylko jeden z nich', (int) ($wyniki[0]['zmian'] ?? 0) + (int) ($wyniki[1]['zmian'] ?? 0) === 1, $opis);

$q = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}hikashop_history WHERE history_order_id = :id AND history_new_status = :status");
$q->execute([':id' => $orderId, ':status' => $config->verifiedStatus]);
$zmianWHistorii = (int) $q->fetchColumn();
wynik('w historii zamowienia jedna zmiana na oplacone', $zmianWHistorii === 1, $zmianWHistorii . ' razy');

$q = $pdo->prepare("SELECT order_status FROM {$prefix}hikashop_order WHERE order_id = :id");
$q->execute([':id' => $orderId]);
wynik('zamowienie jest oplacone', $q->fetchColumn() === $config->verifiedStatus);

echo PHP_EOL . '3. Zapis danych platnosci czeka, az inne zadanie skonczy' . PHP_EOL;

// To polaczenie jest inne niz polaczenie procesu potomnego, wiec blokada
// wzieta tutaj zatrzymuje go tak samo jak inne zadanie witryny.
wynik('test bierze blokade zamowienia', (string) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($nazwa) . ', 1)')->fetchColumn() === '1');

$zapis = dziecko('--dziecko=zapisz', (string) $orderId);

// Czekamy, az potomek dojdzie do zapisu, i trzymamy blokade jeszcze chwile.
$linia = '';
while (($linia = fgets($zapis['wyjscie'])) !== false && !str_starts_with($linia, 'START ')) {
}
$poczatekZapisu = (float) substr((string) $linia, 6);
usleep(1500000);
$zwolnienie = microtime(true);
$pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($nazwa) . ')')->fetchColumn();

$wynikZapisu = wynikDziecka($zapis);

wynik('potomek doszedl do zapisu, zanim blokada zostala zwolniona', $poczatekZapisu > 0 && $poczatekZapisu < $zwolnienie);
wynik('zapis skonczyl sie dopiero po zwolnieniu blokady', (float) ($wynikZapisu['koniec'] ?? 0) >= $zwolnienie, json_encode($wynikZapisu));
wynik('zapis sie udal', ($wynikZapisu['zapisano'] ?? null) === true, json_encode($wynikZapisu));

$q = $pdo->prepare("SELECT order_payment_params FROM {$prefix}hikashop_order WHERE order_id = :id");
$q->execute([':id' => $orderId]);
$dane = OrderPaymentData::read((object) ['order_payment_params' => $q->fetchColumn()]);
wynik('zapisana wartosc jest w bazie', ($dane->p24_test_wyscigu ?? '') === 'zapisane');
wynik('wczesniejsze dane zostaly', ($dane->{OrderPaymentData::VERIFIED_AT} ?? '') !== '');

wynik('blokada jest wolna po pracy', (string) $pdo->query('SELECT IS_FREE_LOCK(' . $pdo->quote($nazwa) . ')')->fetchColumn() === '1');

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy === 0 ? 0 : 1);
