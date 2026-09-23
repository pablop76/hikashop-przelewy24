<?php
/**
 * Buduje paczkę instalacyjną wtyczki.
 *
 * Uruchomienie:
 *   php build.php
 *
 * Numer wersji bierze z manifestu, żeby nie było rozjazdu między
 * nazwą pliku a tym, co zobaczy Joomla po instalacji.
 */

const KATALOG_ZRODEL = __DIR__ . '/src';
const KATALOG_PACZEK = __DIR__ . '/build';
const MANIFEST       = KATALOG_ZRODEL . '/przelewy24.xml';
const NAZWA          = 'plg_hikashoppayment_przelewy24';

/**
 * Pliki, które nigdy nie mogą trafić do paczki.
 */
const WYKLUCZENIA = [
    '.gitignore',
    '.DS_Store',
    'Thumbs.db',
    'desktop.ini',
];

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, 'Brak rozszerzenia zip w PHP, nie ma czym spakować paczki.' . PHP_EOL);
    fwrite(STDERR, 'Odkomentuj extension=zip w php.ini albo uruchom jednorazowo:' . PHP_EOL);
    fwrite(STDERR, '  php -d extension=zip build.php' . PHP_EOL);
    exit(1);
}

if (!is_file(MANIFEST)) {
    fwrite(STDERR, 'Brak manifestu: ' . MANIFEST . PHP_EOL);
    exit(1);
}

$manifest = simplexml_load_file(MANIFEST);

if ($manifest === false) {
    fwrite(STDERR, 'Manifest nie jest poprawnym XML-em.' . PHP_EOL);
    exit(1);
}

$wersja = trim((string) $manifest->version);

if ($wersja === '') {
    fwrite(STDERR, 'Manifest nie podaje numeru wersji.' . PHP_EOL);
    exit(1);
}

if (!is_dir(KATALOG_PACZEK) && !mkdir(KATALOG_PACZEK, 0o775, true) && !is_dir(KATALOG_PACZEK)) {
    fwrite(STDERR, 'Nie udało się utworzyć katalogu ' . KATALOG_PACZEK . PHP_EOL);
    exit(1);
}

$plikPaczki = KATALOG_PACZEK . '/' . NAZWA . '-' . $wersja . '.zip';

if (is_file($plikPaczki) && !unlink($plikPaczki)) {
    fwrite(STDERR, 'Nie udało się usunąć poprzedniej paczki: ' . $plikPaczki . PHP_EOL);
    exit(1);
}

$zip = new ZipArchive();

if ($zip->open($plikPaczki, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, 'Nie udało się otworzyć archiwum do zapisu.' . PHP_EOL);
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(KATALOG_ZRODEL, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$dodane   = 0;
$pominiete = [];

foreach ($iterator as $sciezka => $plik) {
    $wzgledna = str_replace('\\', '/', substr((string) $sciezka, strlen(KATALOG_ZRODEL) + 1));

    if ($wzgledna === '') {
        continue;
    }

    $nazwaPliku = basename($wzgledna);

    if (in_array($nazwaPliku, WYKLUCZENIA, true)) {
        $pominiete[] = $wzgledna;

        continue;
    }

    // Plik z danymi dostępowymi nie ma prawa znaleźć się w paczce,
    // nawet gdyby ktoś go tam przypadkiem skopiował.
    if (str_ends_with($nazwaPliku, '.local.php')) {
        $pominiete[] = $wzgledna;

        continue;
    }

    if ($plik->isDir()) {
        $zip->addEmptyDir($wzgledna);

        continue;
    }

    $zip->addFile((string) $sciezka, $wzgledna);
    $dodane++;
}

if (!$zip->close()) {
    fwrite(STDERR, 'Nie udało się zamknąć archiwum.' . PHP_EOL);
    exit(1);
}

echo 'Paczka:  ' . $plikPaczki . PHP_EOL;
echo 'Wersja:  ' . $wersja . PHP_EOL;
echo 'Plików:  ' . $dodane . PHP_EOL;
echo 'Rozmiar: ' . number_format(filesize($plikPaczki) / 1024, 1, ',', ' ') . ' kB' . PHP_EOL;

if ($pominiete !== []) {
    echo 'Pominięte: ' . implode(', ', $pominiete) . PHP_EOL;
}
