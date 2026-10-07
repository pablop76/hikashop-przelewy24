<?php
/**
 * Dane dostępowe P24 dla testów rozmawiających z sandboksem.
 *
 * Jedno źródło zamiast dwóch: dane bierzemy z opublikowanej metody
 * płatności przelewy24 w lokalnym sklepie, czyli stamtąd, skąd czyta je
 * sama wtyczka. Plik tests/credentials.local.php jest tylko zapasem, gdy
 * sklepu nie ma albo jego metoda płatności nie nadaje się do testów.
 *
 * Wcześniej testy czytały wyłącznie plik. Po zmianie klucza CRC w sklepie
 * plik został ze starym i rejestracja w sandboksie padała na
 * "Incorrect CRC value", choć wtyczka działała (07.10.2026).
 */

require_once __DIR__ . '/autoload.php';

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;

/**
 * Zwraca dane dostępowe i wypisuje, skąd pochodzą.
 *
 * @return array{merchant_id: int, pos_id: int, crc_key: string, api_key: string, test_mode: string}
 */
function daneDostepoweP24(): array
{
    $joomlaPath = getenv('JOOMLA_PATH') ?: 'D:/laragon/www/haskap';
    $plik       = __DIR__ . '/credentials.local.php';

    [$dane, $opis] = daneP24ZMetody(metodaP24WSklepie($joomlaPath));

    if ($dane !== null) {
        echo 'Dane P24:   ' . $opis . ' w sklepie ' . $joomlaPath . PHP_EOL;

        return $dane;
    }

    if (is_file($plik)) {
        echo 'Dane P24:   plik tests/credentials.local.php, bo ' . $opis . PHP_EOL;

        return uporzadkujDaneP24((object) (array) require $plik);
    }

    fwrite(STDERR, 'Brak danych sandboxa P24: ' . $opis . ', a pliku tests/credentials.local.php nie ma.' . PHP_EOL);
    exit(2);
}

/**
 * Odczytuje opublikowaną metodę płatności przelewy24 z bazy sklepu.
 *
 * Bez uruchamiania Joomli: wystarcza jej plik konfiguracji i baza.
 *
 * @return array{payment_id: int, payment_params: string}|string  wiersz metody albo powód, dla którego go nie ma
 */
function metodaP24WSklepie(string $joomlaPath): array|string
{
    $konfiguracja = rtrim($joomlaPath, '/\\') . '/configuration.php';

    if (!is_file($konfiguracja)) {
        return 'nie znaleziono Joomli w ' . $joomlaPath;
    }

    if (!class_exists('JConfig', false)) {
        require_once $konfiguracja;
    }

    $cfg = new JConfig();

    try {
        $pdo = new PDO(
            "mysql:host={$cfg->host};dbname={$cfg->db};charset=utf8mb4",
            $cfg->user,
            $cfg->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );

        $wiersz = $pdo->query(
            "SELECT payment_id, payment_params FROM {$cfg->dbprefix}hikashop_payment
              WHERE payment_type = 'przelewy24' AND payment_published = 1
              ORDER BY payment_id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException) {
        // Bez treści wyjątku: potrafi zawierać nazwę użytkownika bazy.
        return 'baza sklepu nie odpowiada';
    }

    if (!$wiersz) {
        return 'w sklepie nie ma opublikowanej metody przelewy24';
    }

    return ['payment_id' => (int) $wiersz['payment_id'], 'payment_params' => (string) $wiersz['payment_params']];
}

/**
 * Rozstrzyga, czy metoda płatności ze sklepu nadaje się do testów.
 *
 * Metoda w trybie produkcyjnym jest pomijana zawsze. Lokalna kopia sklepu
 * bywa świeżą kopią produkcji, a wtedy niesie prawdziwe dane sprzedawcy
 * i testy rejestrowałyby transakcje w prawdziwym P24.
 *
 * @param  array{payment_id: int, payment_params: string}|string  $metoda  wynik metodaP24WSklepie()
 *
 * @return array{0: array{merchant_id: int, pos_id: int, crc_key: string, api_key: string, test_mode: string}|null, 1: string}
 *         dane i opis źródła albo null i powód odrzucenia
 */
function daneP24ZMetody(array|string $metoda): array
{
    if (is_string($metoda)) {
        return [null, $metoda];
    }

    $nazwa     = 'metoda platnosci id=' . (int) $metoda['payment_id'];
    $parametry = @unserialize((string) $metoda['payment_params'], ['allowed_classes' => [stdClass::class]]);

    if (!is_object($parametry) && !is_array($parametry)) {
        return [null, $nazwa . ' ma nieczytelne parametry'];
    }

    $config = Config::fromPaymentParams((object) (array) $parametry);

    if (!$config->environment->isSandbox()) {
        return [null, $nazwa . ' jest w trybie PRODUKCYJNYM'];
    }

    if (!$config->isComplete()) {
        return [null, $nazwa . ' nie ma kompletu danych P24'];
    }

    return [uporzadkujDaneP24((object) (array) $parametry), $nazwa];
}

/**
 * Sprowadza dane do pięciu pól, których potrzebują testy.
 *
 * Reszta ustawień metody płatności (BLIK w kasie, narzucona metoda,
 * statusy) celowo tu nie trafia: testy mają działać tak samo bez względu
 * na to, co akurat jest włączone w sklepie.
 *
 * @return array{merchant_id: int, pos_id: int, crc_key: string, api_key: string, test_mode: string}
 */
function uporzadkujDaneP24(object $parametry): array
{
    $config = Config::fromPaymentParams($parametry);

    return [
        'merchant_id' => $config->merchantId,
        'pos_id'      => $config->posId,
        'crc_key'     => $config->crc,
        'api_key'     => $config->apiKey,
        // Tryb przechodzi bez zmian. Dane produkcyjne z pliku mają
        // zatrzymać się na blokadzie w samym teście, a nie udawać sandbox.
        'test_mode'   => $config->environment->isSandbox() ? '1' : '0',
    ];
}
