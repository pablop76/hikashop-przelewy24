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
| Zwroty pełne i częściowe | gotowe, z wyzwalaczem przez status zamówienia |
| Pełny przebieg zapłaty w sandboksie | wymaga adresu osiągalnego z internetu |
| BLIK z kodem w sklepie | gotowe |
| Karta w sklepie, Apple Pay, Google Pay | przepływ ustalony, do zbudowania |
| Raty | gotowe, przez narzuconą metodę 303 |

### Uwaga o zwrotach

Wtyczka implementuje `onOrderPaymentRefund()` zgodnie z interfejsem wtyczek
płatności HikaShopa i obsługuje zwroty pełne oraz częściowe.

HikaShop 5.1.2 Business **nie wywołuje tej metody z żadnego miejsca w panelu**.
Deklaruje ją w klasie bazowej i sprawdza flagę `features['refund']` przy
filtrowaniu metod płatności, ale samego zwrotu nigdzie nie inicjuje. Jedyna
inna wtyczka, która ją implementuje, `ogone`, też nie ma kto wywołać.
Dla porównania `onOrderPaymentCapture()` jest wywoływane normalnie,
z `classes/order.php`.

Dlatego zwrot podpinamy pod własne zdarzenie HikaShopa: **zmianę statusu
zamówienia**. W konfiguracji metody płatności wskazujesz status, na przykład
„zwrócone", i od tej chwili nadanie go zamówieniu zgłasza zwrot do Przelewów24.

**Domyślnie wyłączone.** Pozycja „— bez automatycznych zwrotów —” na liście
statusów oznacza, że zwroty nie uruchamiają się same. Automat oddający
pieniądze musi zostać włączony świadomie.

Ta pozycja jest dołożona przez wtyczkę, bo lista statusów HikaShopa nie ma
pustej opcji. Bez niej przeglądarka zaznaczała pierwszy status z listy
i zwykły zapis konfiguracji po cichu włączał zwroty (błąd w 1.0.0 i 1.0.1).

Sprzedawca dowiaduje się o tym w trzech miejscach: w opisie pola, ostrzeżeniem
przy każdym otwarciu konfiguracji z włączonym wyzwalaczem oraz komunikatem po
samym zgłoszeniu zwrotu, z kwotą i numerem zamówienia.

Zabezpieczenia:

- zwrot zgłaszany **raz na zamówienie**, po identyfikatorze zgłoszenia
- tylko dla płatności **wcześniej potwierdzonej** przez `transaction/verify`
- tylko dla zamówień opłaconych tą metodą płatności
- blokada pętli: zapis danych zwrotu sam wywołuje zdarzenie zmiany zamówienia

Zwrot częściowy zostaje do wywołania z kodu:

```php
$plugin = hikashop_import('hikashoppayment', 'przelewy24');
$order  = hikashop_get('class.order')->get($orderId);
$plugin->onOrderPaymentRefund($order, 19.99);   // pusta kwota oznacza całość
```

### BLIK w kasie

Domyślnie wyłączony, bo wymaga osobnej zgody Przelewów24 na koncie sprzedawcy.
Po włączeniu w kasie pojawia się pole na sześciocyfrowy kod. Klient wpisuje
kod z aplikacji banku, klika „Zamawiam i płacę”, zostaje w sklepie
i potwierdza płatność w aplikacji.

**Usługę trzeba mieć włączoną w P24.** To BLIK Level 0, czyli wywołanie
`api/v1/paymentMethod/blik/chargeByCode`, osobne od BLIK-a na stronie płatności.
Bez niej rejestracja transakcji przechodzi, a samo obciążenie kodem kończy się
`401 Incorrect authentication`, mimo poprawnych kluczy. Klient widzi wtedy
komunikat o nieudanym BLIK-u i przycisk przejścia na stronę P24, więc
zamówienie nie przepada. W dzienniku płatności wygląda to tak:

```text
P24 odrzuciło żądanie | endpoint=api/v1/paymentMethod/blik/chargeByCode opis=HTTP 401, kod 401, Incorrect authentication
P24 [UWAGA] Najpewniej BLIK Level 0 (chargeByCode) nie jest włączony na koncie P24. ...
```

Druga linijka to podpowiedź dopisywana przez wtyczkę od 1.0.3. Przy włączonym
BLIK-u w kasie panel konfiguracji metody płatności też przypomina o tej usłudze.

Jak ją włączyć: dokumentacja P24 wymienia jako domyślne tylko `register`,
`verify`, `refund`, `paymentMethods` i `getBySessionId`. Pozostałe usługi
włącza opiekun klienta. Przelewy24 nie podają adresu e-mail wsparcia, jest
[formularz kontaktowy](https://www.przelewy24.pl/centrum-pomocy/wsparcie-techniczne-api/brakuje-odpowiedzi-na-twoje-pytanie).
Poproś o `paymentMethod/blik/chargeByCode` osobno dla sandboxa i produkcji.

Pole nie ma przycisku „Wyślij”, który HikaShop domyślnie dokłada pod własnymi
polami metody płatności. Ten przycisk tylko zapisywał blok płatności, więc
klient wpisywał kod, klikał go i czekał na zapłatę, której nie było.

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

### Raty

Raty w P24 to zwykła płatność przez stronę wyboru, z narzuconą metodą **303**.
Wpisanie tego numeru w polu narzuconej metody płatności kieruje klienta prosto
do rat, z pominięciem ekranu wyboru.

Żeby mieć w sklepie osobno zwykłą płatność i raty, utwórz **drugą metodę
płatności tego samego typu** (HikaShop na to pozwala) i wpisz w niej 303.
Ograniczenia kwotowe ustaw polami najniższej i najwyższej wartości zamówienia,
które HikaShop ma u siebie — nie dublujemy ich we wtyczce.

Narzucona metoda musi być **włączona na koncie sprzedawcy**, inaczej P24
odrzuci rejestrację transakcji. Zbiorcza metoda 303 nie jest dostępna na
każdym koncie; bywają za to metody ratalne konkretnych banków, na przykład
129 dla Alior Banku albo 136 dla mBanku. Listę metod włączonych na koncie
zwraca endpoint `api/v1/payment/methods/{lang}`.

### Karta w sklepie: przepływ

Karty **już działają** przez stronę płatności P24: klient wybiera je tam obok
BLIK-a i przelewu. Poniższe dotyczy wyłącznie formularza osadzonego w sklepie,
który skraca tę drogę.

**Numer karty nigdy nie trafia na serwer sklepu.** Tokenizuje go skrypt P24
w przeglądarce, a sklep dostaje tylko identyfikator referencyjny. Zakres
PCI-DSS pozostaje więc mały.

Przepływ według specyfikacji P24, w kolejności:

1. Przeglądarka ładuje `https://{sandbox|secure}.przelewy24.pl/js/cardTokenizationIframe.min.js`
2. `new Przelewy24CardTokenization(merchantId, sessionId, sign)`, gdzie
   `sign` to `sha384({merchantId, sessionId, crc})` — u nas `Signature::forCardForm()`
3. `P24.render(typ, '#id', opcje)` rysuje formularz w iframe.
   `P24.clear('card'|'cvv'|'exp'|'cardholder')` czyści wybrane pole
4. Zdarzenie `success` niesie `refId`, czyli token karty
5. Serwer wywołuje `transaction/register` z `cardData.means.referenceNumber = refId`
   oraz `transactionType = standard` i dostaje token transakcyjny
6. Przeglądarka ładuje `https://{środowisko}.przelewy24.pl/whitelabel/card/javascript/{token}`
7. Po zdarzeniu `Przelewy24CardWhileLabelHandlerReady` wywołuje
   `Przelewy24CardWhileLabelHandler.config({...})` i `.main()`, co obsługuje 3D Secure
8. Dalej jak zwykle: powiadomienie i `transaction/verify`

Dla płatności cyklicznych i one-click krok 5 używa `transactionType = initial`,
a kolejne obciążenia `1click` albo `recurring`.

**Wymagane metody na koncie sprzedawcy** (bez nich P24 odrzuci rejestrację):
karty 241 lub 242, Google Pay 264 lub 265, Apple Pay 252 lub 253.

Źródło: `https://developers.przelewy24.pl/extended/pl_x_documentation_1.0.yaml`,
sekcje „Wprowadzenie", „Inicjalizacja formularza" i „Przebieg transakcji
kartowych". Strona dokumentacji jest renderowana JavaScriptem, ale sama
specyfikacja OpenAPI pobiera się zwykłym żądaniem.

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

**Jeden `sessionId` na zamówienie, nie na próbę zapłaty.** To rozstrzygnięcie
kosztowało nas prawdziwą wpadkę, więc warto je znać.

Pierwotnie każda próba dostawała własny identyfikator. Wydawało się to
bezpieczniejsze, bo powiadomienie zawsze wskazywało jedną, konkretną próbę.
W praktyce otwierało drogę do podwójnej zapłaty:

1. Klient płaci. P24 księguje transakcję
2. Powiadomienie nie dociera (awaria, timeout, adres nieosiągalny)
3. Klient widzi zamówienie jako nieopłacone i płaci ponownie
4. Nowy identyfikator zakłada w P24 **drugą transakcję** i nadpisuje zapisany
   przy zamówieniu. Klient płaci drugi raz, a powiadomienie o pierwszej
   zapłacie zostaje potem odrzucone jako dotyczące obcej sesji

Teraz identyfikator powstaje raz, przy pierwszej próbie, i jest ponawiany.
Rejestracja z tym samym identyfikatorem zwraca ten sam token, więc klient
wraca do **tej samej** transakcji. Losowa część nadal chroni przed
odgadnięciem, w odróżnieniu od gołego numeru zamówienia.

Dodatkowo zamówienie w statusie opłaconego nie pozwala rozpocząć płatności
od nowa.

**Ponowienie zapłaty bez płatnego HikaShopa.** Gdy płatność nie ruszy,
klient widzi przycisk „Spróbuj zapłacić ponownie”. Do wersji 1.0.3 prowadził
do kasy, a ta po złożeniu zamówienia jest pusta. Własne „zapłać teraz”
(`order&task=pay`) HikaShop ma dopiero od wersji Essential, więc w Starterze
klient zostawał bez wyjścia.

Od 1.0.4 przycisk prowadzi do wtyczki (zadanie `notify` z `p24_action=retry`),
która rejestruje transakcję z tym samym `sessionId` i od razu przekierowuje na
stronę płatności P24. Adres niesie znacznik wyprowadzony z `order_token`
zamówienia, inny niż znacznik powiadomień, więc nie da się go ułożyć dla
cudzego zamówienia. Zamówienia opłaconego, anulowanego ani zwróconego nie da
się w ten sposób opłacić ponownie.

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

**Rejestracja jest idempotentna względem `sessionId`.** Na tym opiera się
ochrona przed podwójną zapłatą. Powtórne wysłanie
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
  duplikaty.php                  ochrona przed podwójną zapłatą
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

Siedem zestawów, każdy o innym zasięgu.

```bash
php tests/run.php          # biblioteka, bez Joomli i bez sieci
php tests/sandbox.php      # prawdziwe API P24, wymaga danych sandboxa
php tests/joomla.php       # wtyczka w zainstalowanej Joomli z HikaShopem
php tests/notification.php # sciezka powiadomienia, siec podstawiona atrapa
php tests/refund.php       # zwroty, siec podstawiona atrapa
php tests/blik.php         # BLIK w kasie, siec podstawiona atrapa
php tests/duplikaty.php    # czy ponowienie nie dubluje transakcji, zywe P24
php tests/retry.php        # przycisk ponowienia zaplaty, siec podstawiona atrapa
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
