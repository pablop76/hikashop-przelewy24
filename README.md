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
| Zwroty | poza wtyczką, robi się je w panelu Przelewy24 |
| Pełny przebieg zapłaty w sandboksie | wymaga adresu osiągalnego z internetu |
| BLIK z kodem w sklepie | gotowe |
| Karta w sklepie, Apple Pay, Google Pay | przepływ ustalony, do zbudowania |
| Raty | gotowe, przez narzuconą metodę 303 |

### Zwroty

**Wtyczka nie zwraca pieniędzy. Zwroty robi się w panelu Przelewy24**, w szczegółach
transakcji. Da się tam zwrócić całość albo część i od razu widać saldo.

Do wersji 1.0.6 wtyczka potrafiła zgłosić zwrot, gdy zamówienie dostawało
wskazany status. W 1.0.7 ten mechanizm został usunięty w całości, z trzech powodów:

- **HikaShop nie ma w panelu czynności „zwróć pieniądze”.** Metodę
  `onOrderPaymentRefund()` deklaruje w klasie bazowej, ale nigdzie jej nie
  wywołuje (sprawdzone w 5.1.2 i 6.6.0). Zwrot dawało się więc podpiąć tylko
  pod zmianę statusu zamówienia
- **Status zmienia się rutynowo**, także hurtem i przez akcje masowe HikaShopa,
  a zwrotu nie da się cofnąć. Jedno kliknięcie za dużo oznaczało przelew wychodzący
- **Kod używany raz na kilkaset zamówień ukrywa błędy.** W 1.0.0 i 1.0.1 zwykły
  zapis konfiguracji po cichu włączał zwroty na pierwszym statusie z listy.
  Do 1.0.6 zwrot przyjęty przez P24 był zgłaszany sprzedawcy jako nieudany,
  bo P24 odpowiada na niego kodem 201, a wtyczka uznawała tylko 200

Po aktualizacji stare ustawienie „status uruchamiający zwrot” jest ignorowane,
a z formularza konfiguracji znika. Niczego nie trzeba przestawiać.

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

Od 1.0.5 rejestracja transakcji przy włączonym BLIK-u w kasie niesie dane
płatnika, których P24 wymaga do `chargeByCode`: obiekt `additional.PSU` z adresem
IP (`REMOTE_ADDR`, bez ufania nagłówkom `X-Forwarded-For`) i przeglądarką klienta.
Przy polu kodu stoi logo BLIK.

Pole nie ma przycisku „Wyślij”, który HikaShop domyślnie dokłada pod własnymi
polami metody płatności. Ten przycisk tylko zapisywał blok płatności, więc
klient wpisywał kod, klikał go i czekał na zapłatę, której nie było.

Pozostawienie pola pustego kieruje klienta zwykłą drogą na stronę płatności
P24, więc włączenie BLIK-a niczego nie zabiera.

Na stronie „Zapłać teraz” HikaShopa (`order&task=pay`) pola kodu nie ma
i klient idzie od razu na stronę płatności P24. Ta strona nie wyświetla
własnego HTML-a metody płatności, a jej kontroler czeka na znacznik
`payment_custom_html`, którego formularz nie wysyła. Do wersji 1.0.5 włącznie
klient krążył przez to między wyborem metody a stroną z samą kwotą i nie
docierał do bramki. Kod zapamiętany w sesji z porzuconej kasy jest tam
pomijany, bo dawno stracił ważność.

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

#### Strona oczekiwania na potwierdzenie

Po przyjęciu kodu klient widzi „Potwierdź płatność w aplikacji banku”.
Od 1.0.9 ta strona sama sprawdza wynik i przechodzi dalej:

- zapłata potwierdzona: podziękowanie za zamówienie,
- bank odrzucił płatność: strona „Płatność nie została potwierdzona”
  z powodem odrzucenia i przyciskiem ponowienia,
- po 150 sekundach bez rozstrzygnięcia: ta sama strona powrotu, która pyta
  P24 ostatni raz.

Do 1.0.8 strona stała w miejscu. Klient potwierdzał płatność, zamówienie się
opłacało, a on dalej czytał, że ma potwierdzić. Po odrzuceniu w banku czekał
bez końca.

Skrypt strony o niczym nie decyduje. Co dwie sekundy pyta sklep
(`p24_action=blik_status`), a sklep odczytuje zamówienie z bazy: zwykle zdąża
je opłacić powiadomienie z P24. Dopiero po ośmiu sekundach, a potem co
dziesięć, sklep pyta też P24 o stan transakcji i opłaconą weryfikuje, tak samo
jak po powrocie klienta z bramki. Dzięki temu strona działa także tam, gdzie
powiadomienia nie dochodzą. Zapłatę nadal potwierdza wyłącznie
`transaction/verify` wykonane z serwera.

**Powód odrzucenia może przyjść tylko osobnym powiadomieniem.** Po przyjęciu
kodu `chargeByCode` odpowiada 201 także wtedy, gdy bank za chwilę odmówi,
a odrzucona transakcja wygląda w `transaction/by/sessionId` tak samo jak
trwająca, także godzinę później. Dlatego przy włączonym BLIK-u w kasie
rejestracja niesie `urlCardPaymentNotification` (nazwa myląca, to adres
powiadomień BLIK). Według dokumentacji P24 przysyła tam wynik autoryzacji.
Wtyczka sprawdza podpis i zapisuje przyczynę przy zamówieniu. Statusu
zamówienia to powiadomienie nie zmienia: zamówienie zostaje do opłacenia.

**Na sandboksie to powiadomienie nie przyszło ani razu** (09.10.2026, serwer
widoczny z internetu). Nie wysłało go siedem transakcji: odrzucone i udane,
zarejestrowane bez metody i z metodą 181, tak jak robi to oficjalna wtyczka
P24 dla WooCommerce. Zwykłe powiadomienie o wpłacie dochodziło w tym samym
czasie bez przeszkód. Dopóki P24 nie wysyła powiadomień BLIK, odrzucona
płatność kończy się więc limitem 150 sekund albo kliknięciem „Sprawdź, czy
płatność doszła” i ogólnym komunikatem z ponowieniem. Oficjalna wtyczka ma
na tę okoliczność taki sam zapas, 120 sekund. Czy powiadomienia trzeba
włączyć na koncie, wie wsparcie P24.

Kształt powiadomienia jest wzięty z dokumentacji i z oficjalnej wtyczki, bo
prawdziwego nie udało się zobaczyć. Gdy podpis się nie zgadza, wtyczka zapisuje
w dzienniku treść powiadomienia bez podpisu, a klient po upływie czasu trafia
na stronę powrotu z ogólnym komunikatem. Nic poza brakiem konkretnego powodu
wtedy nie przepada.

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

Od 1.0.10 pierwszy wiersz konfiguracji metody płatności podaje wersję
zainstalowanej wtyczki i datę jej wydania. Joomla pokazuje wersję tylko na
liście rozszerzeń (System → Zarządzaj → Rozszerzenia), a przy wyłączonym
serwerze aktualizacji nic nie podpowiada, że jest nowsza. Gdy manifest
instalacji podaje inną wersję niż pliki wtyczki, czyli po instalacji przerwanej
w połowie, ten sam wiersz pokazuje ostrzeżenie.

Wtyczka instaluje dwa logotypy, `przelewy24.svg` i `BLIK.svg`, do własnego
katalogu `media/plg_hikashoppayment_przelewy24`, a potem kopiuje je do obrazków
metod płatności HikaShopa (`media/com_hikashop/images/payment`), żeby dało się
je wybrać w polu „Obrazki” metody płatności. Istniejącego tam pliku o tej samej
nazwie nie nadpisuje. Logotypy pochodzą z oficjalnych materiałów BLIK i Przelewów24.

**Przed odinstalowaniem wersji 1.0.6 lub starszej najpierw zaktualizuj wtyczkę.**
Starsze wersje wskazywały katalog obrazków HikaShopa wprost w manifeście,
a Joomla przy odinstalowaniu kasuje cały katalog docelowy, nie tylko wymienione
pliki. Odinstalowanie usuwało więc logotypy wszystkich metod płatności w sklepie.
Od 1.0.7 Joomla kasuje wyłącznie katalog wtyczki, a kopie w obrazkach HikaShopa
zostają na miejscu. Można je usunąć ręcznie.

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

**Każda próba zapłaty ma własny `sessionId`, a zamówienie pamięta wszystkie.**
To rozstrzygnięcie zmieniało się dwa razy, więc warto znać oba powody.

Pierwotnie każda próba dostawała własny identyfikator, a zapisany przy
zamówieniu był nadpisywany. Otwierało to drogę do podwójnej zapłaty:

1. Klient płaci. P24 księguje transakcję
2. Powiadomienie nie dociera (awaria, timeout, adres nieosiągalny)
3. Klient widzi zamówienie jako nieopłacone i płaci ponownie
4. Nowy identyfikator zakłada w P24 **drugą transakcję** i nadpisuje zapisany
   przy zamówieniu. Klient płaci drugi raz, a powiadomienie o pierwszej
   zapłacie zostaje potem odrzucone jako dotyczące obcej sesji

Do 1.0.7 identyfikator powstawał więc raz i był ponawiany: P24 zwraca wtedy
ten sam token, czyli tę samą transakcję. Okazało się, że tej samej transakcji
po nieudanej płatności nie da się dokończyć. Jej strona płatności od razu
odsyła klienta do sklepu, więc ponowienie kręciło się w kółko (sandbox,
09.10.2026, szczegóły w rozdziale o zachowaniach P24).

Od 1.0.8 każda próba znów ma własny identyfikator, ale bez dawnej wady:

- sesja poprzedniej próby nie jest nadpisywana, tylko przechodzi na listę
  wcześniejszych sesji zamówienia (ostatnie 10)
- przed każdą nową próbą wtyczka pyta P24 o **każdą** sesję zamówienia
  i opłaconą potwierdza, zamiast zakładać kolejną transakcję
- powiadomienie o wpłacie na dowolną sesję z listy należy do zamówienia.
  Tak dochodzi przelew tradycyjny księgowany po godzinach i wpłata
  z odnośnika w wiadomości od P24
- druga wpłata za zweryfikowane już zamówienie nie jest weryfikowana, więc
  zostaje w P24 do dyspozycji klienta

Losowa część identyfikatora nadal chroni przed odgadnięciem, w odróżnieniu
od gołego numeru zamówienia. Zamówienie w statusie opłaconego nie pozwala
rozpocząć płatności od nowa.

**Przed startem płatności pytamy P24, czy za zamówienie już nie zapłacono.**
Powiadomienie potrafi nie dotrzeć wcale: sklep ma wtedy zamówienie nieopłacone
i pokazuje „Zapłać teraz”, choć pieniądze są już w P24. Do 1.0.7 kliknięcie
rejestrowało transakcję jeszcze raz i wysyłało klienta do bramki.

Od 1.0.8 każde ponowne rozpoczęcie płatności, z przycisku wtyczki i z „Zapłać
teraz” HikaShopa, zaczyna się od `transaction/by/sessionId` dla każdej sesji
zamówienia, od najnowszej. Jeżeli P24 ma wpłatę (stan 1 albo 2) w kwocie
i walucie zamówienia, wtyczka od razu robi `transaction/verify` i potwierdza
zamówienie tak samo jak po powiadomieniu. Klient zamiast bramki widzi
informację, że za zamówienie już zapłacono. Pozostałe przypadki:

- weryfikacja kończy się błędem: status zostaje, klient czyta, że wpłata jest
  odnotowana i żeby nie płacił ponownie, a sprzedawca dostaje jedną wiadomość
- wpłata w innej kwocie albo walucie niż zamówienie: bez weryfikacji i bez
  nowej płatności, klient ma się skontaktować ze sklepem
- wpłata zwrócona w P24 (stan 3): nowej płatności nie zaczynamy
- P24 nie zna transakcji albo nie ma wpłaty: nowa sesja i zwykła rejestracja
- P24 nie odpowiada: nowej transakcji **nie** zakładamy, bo nie wiemy, czy
  poprzednia próba nie została opłacona. Klient może spróbować za chwilę

Pierwsze złożenie zamówienia nie kosztuje dodatkowego pytania, bo zamówienie
nie ma jeszcze zapisanej sesji. To nadal nie jest uznawanie zapłaty na słowo
przeglądarki: o stan pytamy P24 z serwera, a status zmienia dopiero udane
`transaction/verify`.

**Klient wracający z bramki nie trafia wprost na podziękowanie.** P24 odsyła
klienta na `urlReturn` po zapłacie, po błędzie i po rezygnacji, bez żadnej
informacji o wyniku, a po nieudanej płatności nie pokazuje mu nawet
komunikatu. Do 1.0.7 adres powrotu prowadził do strony podziękowania
HikaShopa, więc nieudana płatność wyglądała w sklepie jak udane zamówienie.

Od 1.0.8 adres powrotu prowadzi do wtyczki, która zadaje P24 to samo pytanie
co przed nową próbą:

- wpłata jest: weryfikacja, status opłaconego i dopiero wtedy podziękowanie.
  Sklep nie musi czekać na powiadomienie, więc działa to także tam, gdzie
  powiadomienia nie dochodzą
- wpłaty nie ma: strona „Płatność nie została potwierdzona” z przyciskiem
  ponowienia. Tekst jest ostrożny, bo P24 nie odróżnia tu płatności nieudanej
  od przelewu tradycyjnego, który dopiero idzie
- P24 nie odpowiada: neutralna informacja, bez zgadywania w żadną stronę

Przycisk „Przejdź do płatności” na stronie przejścia też prowadzi przez
sklep, a nie wprost do bramki. Klient klika go zwykle po powrocie z bramki
przyciskiem „wstecz”, a zapisany na stronie adres bramki jest wtedy martwy.

Adresy, które otwiera klient (powrót i ponowienie), niosą parametr
`skip_system_notification=1`. Bez niego wtyczka systemowa HikaShopa
przechwytuje zadanie `notify` i oddaje wynik bez szablonu witryny, czyli
goły tekst na białym tle. Adres powiadomień dla P24 tego parametru nie ma.

Te same adresy i strona podziękowania używają pozycji menu kasy wskazanej
w konfiguracji HikaShopa (`checkout_itemid`), a nie tej, z którą klient
przyszedł. „Zapłać teraz” z e-maila niesie pozycję menu sklepu albo konta
i bez tego podziękowanie wyświetlało się w ich układzie, na przykład z boczną
kolumną. Gdy sklep nie wskazał pozycji menu kasy, zostaje ta z żądania.

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

**O tym, czy zapłata jest potwierdzona, decyduje nasz znacznik, nie status
zamówienia.** Po udanym `transaction/verify` wtyczka zapisuje przy zamówieniu
chwilę potwierdzenia. Kolejne powiadomienia dla takiego zamówienia są pomijane.
Sam status tego nie rozstrzyga, bo sprzedawca zmienia go ręcznie:

- zamówienie potwierdzone ręcznie, zanim doszło powiadomienie, **i tak jest
  weryfikowane**. Bez weryfikacji Przelewy24 nie rozliczają wpłaty i zostaje
  ona do dyspozycji klienta. Do 1.0.6 weryfikacja była w takim przypadku pomijana
- zamówienie, które ma już status opłaconego albo poszło do wysyłki, **nie jest
  cofane** do statusu potwierdzenia i klient nie dostaje drugiego e-maila.
  Za opłacone uchodzą statusy, w których HikaShop wystawia fakturę
- drugiej, osobnej wpłaty za zweryfikowane zamówienie nie weryfikujemy, więc
  wraca ona do klienta

**Błąd weryfikacji nie zmienia statusu zamówienia.** Przelewy24 wysyłają
powiadomienia tylko dla transakcji opłaconych. Jeżeli po poprawnie podpisanym
powiadomieniu weryfikacja kończy się błędem (401, 400, 500, brak odpowiedzi),
to kłopot leży po stronie sklepu albo bramki, a klient zapłacił. Do 1.0.6
taka odpowiedź nadawała zamówieniu status nieudanej płatności, czyli zwykle
je anulowała i wysyłała klientowi e-mail. Teraz status zostaje, a sprzedawca
dostaje jedną wiadomość na adres powiadomień o płatnościach z konfiguracji
HikaShopa. Przelewy24 ponawiają powiadomienie przez kilka godzin, więc po
usunięciu przyczyny zapłata potwierdza się sama. Status nieudanej płatności
nadaje wyłącznie jawna odpowiedź P24 ze statusem innym niż `success`.

**Kwota pochodzi z jednego miejsca.** Rejestracja transakcji i obsługa
powiadomienia czytają kwotę zamówienia z bazy. Koszyk trzyma ją jako liczbę
zmiennoprzecinkową o pełnej precyzji, a baza zaokrągla do pięciu miejsc;
tuż pod granicą pół grosza (koszyk 10,0049999, baza 10,00500) obie postacie
dawały różne grosze i prawidłowa zapłata byłaby odrzucana.

**Adres e-mail nie jest przycinany.** Przelewy24 przyjmują adresy do 50 znaków.
Przycięty adres należy do kogoś innego, więc przy dłuższym płatność się nie
zaczyna, a klient widzi, w czym rzecz.

**Sekrety nie trafiają do logu.** `Logger` wymazuje wartości klucza API i CRC
zarówno z pól kontekstu, jak i z treści komunikatów.

## Zachowania P24 ustalone na sandboksie

Sprawdzone 23.09.2026 i 07.10.2026 na koncie testowym. Warto je znać, bo
dokumentacja ich nie opisuje albo opisuje niejednoznacznie.

**Dostęp do API wymaga zarejestrowania adresu IP.** Bez wpisu w panelu
(„Moje dane" → „Dane API i konfiguracja" → „Adres IP") każde wywołanie
kończy się `HTTP 401 Incorrect authentication` — tak samo jak przy błędnym
kluczu, więc po kodzie odpowiedzi nie da się tych dwóch przyczyn odróżnić.
Adres musi być tym, z którego wychodzi ruch serwera sklepu.

**Hasłem uwierzytelniania Basic jest „Klucz do raportów".** W panelu nie
nazywa się kluczem API, ale to właśnie on. „Klucz do zamówień" obsługuje
stare API formularzowe i w REST jest nieużywany. Loginem jest identyfikator
sprzedawcy. Dokumentacja mówi w tym miejscu o `posId`, ale oficjalna wtyczka
Przelewy24 dla WooCommerce loguje się identyfikatorem sprzedawcy, więc robimy
tak samo. Na koncie, na którym oba identyfikatory są równe, tej różnicy nie
da się sprawdzić.

**`transaction/by/sessionId` zmienia odpowiedź razem z losem transakcji.**
Dopóki klient nie wybrał sposobu zapłaty, P24 odpowiada `HTTP 404 Transaction
not found`. Po wyborze sposobu zapłaty transakcja dostaje numer i stan 0, bez
względu na to, czy płatność się nie udała, została porzucona, czy przelew
dopiero idzie. Stan 1 pojawia się od razu po zapłacie, jeszcze zanim klient
wróci do sklepu, a stan 2 po `transaction/verify`. Strona powrotu klienta
może się więc na tym pytaniu opierać, byle nie brała stanu 0 za odmowę.

**Rejestracja jest idempotentna względem `sessionId`.** Powtórne wysłanie
`transaction/register` z tym samym identyfikatorem sesji i tą samą kwotą
nie kończy się błędem: P24 zwraca ten sam token co za pierwszym razem.
Licznik 15 minut na stronie płatności nie zaczyna się przy tym od nowa.

**Transakcji po nieudanej płatności nie da się dokończyć.** Po wyborze
„Błąd płatności”, „Brak wpłaty” albo „Oczekiwanie na wpłatę” w banku testowym
P24 odsyła klienta prosto na `urlReturn`, bez komunikatu. Ponowne wejście na
stronę płatności z tym samym tokenem kończy się tak samo: `trnRequest`
przechodzi od razu w `trnResult` i wraca do sklepu, także w czystej
przeglądarce. Powtórna rejestracja tej samej sesji przywraca listę metod, ale
wybranie metody użytej poprzednio znów odsyła do sklepu. Nowa sesja daje nowy
token i opłaca się normalnie w tej samej przeglądarce, a stara sesja zostaje
w stanie 0. Sprawdzone 09.10.2026.

**Weryfikacja nieopłaconej transakcji kończy się błędem, nie odpowiedzią
negatywną.** P24 zwraca `HTTP 400` z komunikatem `Error call 2`, a nie
`HTTP 200` ze statusem innym niż `success`. Obsługa musi to traktować
jako brak potwierdzenia zapłaty, nie jako awarię.

**Powtórna weryfikacja opłaconej transakcji znów odpowiada `success`.**
Druga `transaction/verify` dla tej samej transakcji zwraca `HTTP 200`
i `status: success`, a nie błąd. Przed dublowaniem musi więc chronić sklep.

**Nie każde powodzenie to kod 200.** Rejestracja i weryfikacja odpowiadają
200, ale `transaction/refund` odpowiada 201, a pole `data` jest wtedy listą
pozycji. `blik/chargeByCode` też odpowiada 201 (potwierdzone 09.10.2026).
Wtyczka uznaje za powodzenie oba kody.

**BLIK w sklepie na sandboksie.** Kod `777XXX` jest przyjmowany i transakcja
od razu ma stan 1, bez aplikacji banku. Inny kod kończy się `HTTP 400`
z kodem 28 („Incorrect ticket!”). Kwota transakcji wymusza odrzucenie w banku,
na przykład 5,99 zł brak środków, 81,88 zł zły PIN, 8,99 zł upływ czasu
(tabelę kwot podaje wsparcie P24). Przy takiej kwocie `chargeByCode` nadal
odpowiada 201, a stan transakcji zostaje 0. Do próby udanej trzeba więc brać
kwotę spoza tabeli. Sprawdzone 09.10.2026.

**Kwota musi być liczbą całkowitą także w zapisie JSON.** Wartość
`1998.9999999999998` kończy się błędem `400 Invalid amount`.

**Ta sama sesja z inną kwotą daje nowy token.** Powtórna rejestracja z tym
samym `sessionId`, ale inną kwotą, nie jest odrzucana: P24 zwraca nowy token
i pod jedną sesją żyją wtedy dwie rejestracje.

**Adres e-mail może mieć najwyżej 50 znaków.** Adres o 50 znakach przechodzi,
o 51 kończy rejestrację błędem `400 Invalid email`. Pola nieobowiązkowe nie
są sprawdzane tak ściśle: telefon ze spacjami i myślnikami przechodzi.

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
  blik.php                       BLIK w kasie
  blik-oczekiwanie.php           strona oczekiwania BLIK i powiadomienie o odrzuceniu
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

Dziewięć zestawów, każdy o innym zasięgu.

```bash
php tests/run.php          # biblioteka, bez Joomli i bez sieci
php tests/sandbox.php      # prawdziwe API P24, wymaga danych sandboxa
php tests/joomla.php       # wtyczka w zainstalowanej Joomli z HikaShopem
php tests/notification.php # sciezka powiadomienia, siec podstawiona atrapa
php tests/blik.php         # BLIK w kasie, siec podstawiona atrapa
php tests/blik-oczekiwanie.php # strona oczekiwania BLIK i powiadomienie o odrzuceniu, siec podstawiona atrapa
php tests/duplikaty.php    # sesje zamowienia i ochrona przed podwojna zaplata, zywe P24
php tests/retry.php        # ponowienie zaplaty, powrot z bramki i wplata, o ktorej sklep nie wie, siec podstawiona atrapa
php tests/email.php        # adres e-mail klienta, takze goscia
```

`run.php` obejmuje przeliczanie kwot, kolejność kluczy w podpisach, odrzucanie
niepoprawnych powiadomień, budowanie żądania rejestracji i odczyt danych
zapisanych przy zamówieniu. Nie wymaga Joomli, HikaShopa ani composera.

`sandbox.php` odzywa się do sandboksa P24 i sprawdza dane dostępowe,
rejestrację transakcji, adres strony płatności oraz obsługę błędów.

Dane konta testowego bierze z opublikowanej metody płatności `przelewy24`
w lokalnym sklepie, czyli z tego samego miejsca co wtyczka
(`tests/dane-dostepowe.php`). Klucze wpisuje się więc raz, w konfiguracji
metody płatności. Metoda w trybie produkcyjnym jest pomijana, bo lokalna
kopia sklepu bywa kopią produkcji. Gdy sklepu albo metody nie ma, test czyta
zapasowy plik `tests/credentials.local.php`, którego nie ma w repozytorium.
Pierwsza linia wyniku mówi, skąd dane pochodzą. Wzór pliku:

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
