# Przelewy24 dla HikaShop

Wtyczka płatności Przelewy24 dla sklepu HikaShop działającego na Joomli 5 i 6.

## Wymagania

| Składnik | Wersja |
| --- | --- |
| Joomla | 5.0 lub nowsza (zgodna z 6.x) |
| HikaShop | 5.0 lub nowsza |
| PHP | 8.1 minimum, zalecane 8.3 |
| Konto | Przelewy24 z dostępem do REST API |

## Stan prac

Wtyczka powstaje etapami. Rdzeń integracji jest gotowy i pokryty testami.

| Etap | Stan |
| --- | --- |
| Biblioteka P24 (podpisy, kwoty, klient API, logowanie) | gotowe |
| Weryfikacja powiadomień | gotowe |
| Przekierowanie na stronę płatności P24 | gotowe |
| Formularz konfiguracji, tłumaczenia pl i en, paczka instalacyjna | gotowe |
| Zwroty pełne i częściowe | gotowe, patrz uwaga niżej |
| Pełny przebieg zapłaty w sandboksie | wymaga adresu osiągalnego z internetu |
| BLIK z kodem w sklepie | gotowe |
| Karta w sklepie, Apple Pay, Google Pay | planowane |
| Raty | planowane |

### Uwaga o zwrotach

Wtyczka implementuje `onOrderPaymentRefund()` zgodnie z interfejsem wtyczek
płatności HikaShopa i obsługuje zwroty pełne oraz częściowe.

HikaShop 5.1.2 Business **nie wywołuje tej metody z żadnego miejsca w panelu**.
Deklaruje ją w klasie bazowej i sprawdza flagę `features['refund']` przy
filtrowaniu metod płatności, ale samego zwrotu nigdzie nie inicjuje. Jedyna
inna wtyczka, która ją implementuje, `ogone`, też nie ma kto wywołać.
Dla porównania `onOrderPaymentCapture()` jest wywoływane normalnie,
z `classes/order.php`.

Zwrot da się więc na razie uruchomić tylko z własnego kodu:

```php
$plugin = hikashop_import('hikashoppayment', 'przelewy24');
$order  = hikashop_get('class.order')->get($orderId);
$plugin->onOrderPaymentRefund($order, 19.99);   // pusta kwota oznacza całość
```

Zwrot wymaga, żeby przy zamówieniu zapisany był identyfikator transakcji
nadany przez P24. Trafia tam z powiadomienia, więc zwrócić można wyłącznie
płatność, która została wcześniej potwierdzona.

### BLIK w kasie

Domyślnie wyłączony, bo wymaga osobnej zgody Przelewów24 na koncie sprzedawcy.
Po włączeniu w kasie pojawia się pole na sześciocyfrowy kod. Klient wpisuje
kod z aplikacji banku, zostaje w sklepie i potwierdza płatność w aplikacji.

Pozostawienie pola pustego kieruje klienta zwykłą drogą na stronę płatności
P24, więc włączenie BLIK-a niczego nie zabiera.

Trzy rzeczy rozstrzygnięte świadomie:

**Kod nie trafia do bazy.** Jest jednorazowy i ważny około dwóch minut, więc
żyje tylko w stanie sesji i jest z niej usuwany w chwili użycia. Inaczej
groziłby wysłaniem przy następnym zamówieniu, gdy jest już nieważny.

**Zużytego kodu nie wysyłamy drugi raz.** Gdy P24 odpowie kodem 35, czyli
„kod już użyty", obciążenie mogło dojść do skutku, a tylko odpowiedź do nas
nie dotarła. Zamówienie zostaje wtedy w stanie oczekiwania na powiadomienie,
zamiast próbować ponownie.

**Nie każde odrzucenie pozwala spróbować jeszcze raz.** Przy przeterminowanym
albo błędnym kodzie prosimy o nowy. Przy braku środków czy odmowie banku nowy
kod niczego nie zmieni, więc od razu kierujemy klienta do innej metody.

Strona oczekiwania na potwierdzenie **nie odpytuje P24 i nie odświeża się**.
Zapłatę potwierdza powiadomienie wysłane przez P24 na serwer, a nie cokolwiek,
co dzieje się w przeglądarce klienta.

## Instalacja

```bash
php -d extension=zip build.php
```

Paczka powstaje w `build/`. Instaluje się ją normalnie przez Rozszerzenia →
Zainstaluj w panelu Joomli. Budowanie wymaga rozszerzenia `zip` w PHP.

Po instalacji metodę płatności dodaje się w HikaShopie: System → Metody
płatności → Nowa → Przelewy24.

## Zasady integracji

Kilka reguł, od których zależy poprawność rozliczeń. Są wymuszone w kodzie,
nie tylko opisane.

**Powrót klienta nie jest potwierdzeniem zapłaty.** Adres `urlReturn` służy
wyłącznie do pokazania klientowi wyniku. Status zamówienia zmienia się
dopiero po poprawnej odpowiedzi z `transaction/verify`.

**Kwoty są liczbami całkowitymi groszy.** Przeliczanie robi `Amount::toMinorUnit()`,
które mnoży przed zaokrągleniem. Popularny zapis `round($cena, 2) * 100` daje
dla około 9% kwot liczbę zmiennoprzecinkową, na przykład 1,15 zł jako
`114.99999999999999`, i taką wartość `json_encode` wysyła do P24.

**Każda próba zapłaty ma własny `sessionId`.** Nie dlatego, że P24 odrzuca
powtórzenia — sprawdzone na sandboksie, przyjmuje je i zwraca ten sam token.
Powód jest inny: gdyby dwie próby zapłaty dzieliły identyfikator sesji,
powiadomienie przestałoby jednoznacznie wskazywać, której próby dotyczy.
Powiązanie z zamówieniem trzymamy po stronie sklepu.

**Powiadomienia są weryfikowane.** Sprawdzamy podpis, identyfikator sprzedawcy,
zgodność sesji z zapisaną przy zamówieniu oraz kwotę i walutę. Niezgodność
w którymkolwiek z tych punktów oznacza, że zamówienia nie wolno ruszyć.

**Sekrety nie trafiają do logu.** `Logger` wymazuje wartości klucza API i CRC
zarówno z pól kontekstu, jak i z treści komunikatów.

## Zachowania P24 ustalone na sandboksie

Sprawdzone 23.09.2026 na koncie testowym, skryptem `tests/sandbox.php`.
Warto je znać, bo dokumentacja ich nie opisuje.

**Dostęp do API wymaga zarejestrowania adresu IP.** Bez wpisu w panelu
(„Moje dane" → „Dane API i konfiguracja" → „Adres IP") każde wywołanie
kończy się `HTTP 401 Incorrect authentication` — tak samo jak przy błędnym
kluczu, więc po kodzie odpowiedzi nie da się tych dwóch przyczyn odróżnić.
Adres musi być tym, z którego wychodzi ruch serwera sklepu.

**Hasłem uwierzytelniania Basic jest „Klucz do raportów".** W panelu nie
nazywa się kluczem API, ale to właśnie on. „Klucz do zamówień" obsługuje
stare API formularzowe i w REST jest nieużywany. Loginem jest identyfikator
sprzedawcy, nie identyfikator sklepu.

**`transaction/by/sessionId` nie widzi transakcji przed zapłatą.** Dla
zarejestrowanej, ale nieopłaconej transakcji P24 odpowiada `HTTP 404
Transaction not found`. Strona powrotu klienta nie może więc opierać się
na tym endpoincie, bo dla porzuconej płatności nie dostanie nic.

**Rejestracja jest idempotentna względem `sessionId`.** Powtórne wysłanie
`transaction/register` z tym samym identyfikatorem sesji i tą samą kwotą
nie kończy się błędem — P24 zwraca ten sam token co za pierwszym razem.

**Weryfikacja nieopłaconej transakcji kończy się błędem, nie odpowiedzią
negatywną.** P24 zwraca `HTTP 400` z komunikatem `Error call 2`, a nie
`HTTP 200` ze statusem innym niż `success`. Obsługa musi to traktować
jako brak potwierdzenia zapłaty, nie jako awarię.

## Struktura

```text
plugin/
  przelewy24.php                 most zgodności: alias dla HikaShopa
  przelewy24.xml                 manifest instalacyjny
  przelewy24_configuration.php   formularz konfiguracji w panelu
  przelewy24_end.php             strona przejścia do bramki
  script.php                     skrypt instalacyjny, włącza wtyczkę
  services/provider.php          rejestracja w kontenerze Joomli
  src/
    Extension/Przelewy24.php     klasa wtyczki
    Payment/                     integracja P24, PHP 8.1+
  language/                      pl-PL i en-GB
tests/
  run.php                        biblioteka, bez Joomli i bez sieci
  sandbox.php                    prawdziwe API P24
  joomla.php                     wtyczka w zainstalowanej Joomli
  notification.php               ścieżka powiadomienia
  refund.php                     zwroty
  blik.php                       BLIK w kasie
  bootstrap-joomla.php           wspólny rozruch testów integracyjnych
```

### Dlaczego most zgodności

Wtyczka jest zbudowana zgodnie z zaleceniami Joomli 5: przestrzeń nazw
zadeklarowana w manifeście, klasa w `src/Extension`, rejestracja przez
`services/provider.php`.

HikaShop ładuje jednak wtyczki płatności obok kontenera Joomli. Funkcja
`hikashop_import()` robi `require_once` na sztywnej ścieżce
`plugins/hikashoppayment/<nazwa>/<nazwa>.php`, sprawdza `class_exists()`
dla nazwy `plgHikashoppayment<Nazwa>` i tworzy obiekt tej klasy. Dlatego
`przelewy24.php` istnieje nadal, ale zawiera już tylko `class_alias()`
na właściwą klasę. Obie drogi prowadzą do tego samego obiektu.

Klasa z przestrzeni nazw dziedziczy po `hikashopPaymentPlugin`, a tę
HikaShop rejestruje do autoloadu dopiero przy pierwszym wczytaniu swojego
`helper.php`. Autoloader Joomli potrafi sięgnąć po naszą klasę wcześniej,
na przykład podczas instalacji w Menedżerze Rozszerzeń, i wtedy
`extends` kończy się błędem krytycznym. Stąd guard na początku
`src/Extension/Przelewy24.php`, który w razie potrzeby doczytuje
`helper.php` przed deklaracją klasy.

Metody `on*` muszą mieć sygnatury bez typów, zgodne z klasą bazową
HikaShopa: dodanie typu tam, gdzie przodek go nie ma, łamie
kontrawariancję i kończy się błędem krytycznym. Cała otypowana logika
siedzi więc w przestrzeni `Payment`, która nie zależy ani od HikaShopa,
ani od Joomli poza klientem HTTP.

## Testy

Sześć zestawów, każdy o innym zasięgu.

```bash
php tests/run.php          # biblioteka, bez Joomli i bez sieci
php tests/sandbox.php      # prawdziwe API P24, wymaga danych sandboxa
php tests/joomla.php       # wtyczka w zainstalowanej Joomli z HikaShopem
php tests/notification.php # sciezka powiadomienia, siec podstawiona atrapa
php tests/refund.php       # zwroty, siec podstawiona atrapa
php tests/blik.php         # BLIK w kasie, siec podstawiona atrapa
```

`run.php` obejmuje przeliczanie kwot, kolejność kluczy w podpisach, odrzucanie
niepoprawnych powiadomień, budowanie żądania rejestracji i odczyt danych
zapisanych przy zamówieniu. Nie wymaga Joomli, HikaShopa ani composera.

`sandbox.php` odzywa się do sandboksa P24 i sprawdza dane dostępowe,
rejestrację transakcji, adres strony płatności oraz obsługę błędów. Czyta
dane z `tests/credentials.local.php`, którego nie ma w repozytorium. Wzór:

```php
<?php

return [
    'merchant_id' => 123456,
    'crc_key'     => '...',
    'api_key'     => '...',
    'test_mode'   => '1',
];
```

`joomla.php` ładuje wtyczkę tak, jak robi to HikaShop, i sprawdza autoloader,
dziedziczenie, sygnatury metod oraz tłumaczenia. Wymaga wcześniejszej
instalacji paczki. Ścieżkę do Joomli można podać zmienną `JOOMLA_PATH`.

Pełną procedurę testów płatności w sandboksie opisuje `playbooks/p24-testing.md`
w katalogu nadrzędnym.

## Licencja

GNU General Public License v3 lub nowsza.
