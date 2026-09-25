<?php
/**
 * Test adresu e-mail wysyłanego do P24 przy rejestracji transakcji.
 *
 * Uruchomienie:
 *   php tests/email.php
 *
 * Przy zakupie gościa HikaShop przekazuje w zamówieniu user_email jako
 * tablicę z jednym adresem. Wtyczka wysyłała wtedy do P24 "Array"
 * i rejestracja padała błędem "Invalid email" (25.09.2026).
 */

require_once __DIR__ . '/bootstrap-joomla.php';

use Pablop76\Plugin\HikashopPayment\Przelewy24\Extension\Przelewy24;

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
    echo '  BLAD ' . $opis . ($szczegol !== '' ? ': ' . $szczegol : '') . PHP_EOL;
}

final class WtyczkaEmail extends Przelewy24
{
    public function email($order): string
    {
        return $this->customerEmail($order);
    }
}

$dispatcher = Joomla\CMS\Factory::getContainer()->get('dispatcher');
$wtyczka    = new WtyczkaEmail($dispatcher, ['name' => 'przelewy24', 'type' => 'hikashoppayment']);
$wtyczka->user = null;

$zamowienie = static function ($userEmail): object {
    return (object) ['customer' => (object) ['user_email' => $userEmail], 'order_user_id' => 0];
};

echo PHP_EOL . 'Adres e-mail klienta' . PHP_EOL;

$e = $wtyczka->email($zamowienie('klient@example.com'));
wynik('zalogowany klient: adres jako tekst', $e === 'klient@example.com', $e);

$e = $wtyczka->email($zamowienie(['gosc@example.com']));
wynik('gosc: adres w tablicy', $e === 'gosc@example.com', $e);

$e = $wtyczka->email($zamowienie('  spacje@example.com '));
wynik('spacje wokol adresu obciete', $e === 'spacje@example.com', $e);

$e = $wtyczka->email($zamowienie([]));
wynik('pusta tablica daje pusty adres, nie "Array"', $e === '', $e);

$e = $wtyczka->email($zamowienie('to-nie-adres'));
wynik('bledny adres odrzucony', $e === '', $e);

$wtyczka->user = (object) ['user_email' => 'sesja@example.com'];
$e = $wtyczka->email($zamowienie(''));
wynik('brak adresu w zamowieniu: adres z sesji', $e === 'sesja@example.com', $e);

echo PHP_EOL . str_repeat('-', 60) . PHP_EOL;
echo 'Zdane: ' . $zdane . ', niezdane: ' . $bledy . PHP_EOL;

exit($bledy > 0 ? 1 : 0);
