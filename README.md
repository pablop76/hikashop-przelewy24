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
| Przekierowanie na stronę płatności P24 | w toku |
| BLIK z kodem w sklepie | planowane |
| Karta w sklepie, Apple Pay, Google Pay | planowane |
| Raty | planowane |
| Zwroty pełne i częściowe | planowane |

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
src/
  przelewy24.php                 klasa wtyczki widziana przez HikaShop
  przelewy24_configuration.php   formularz konfiguracji w panelu
  przelewy24_end.php             strona przejścia do bramki
  przelewy24.xml                 manifest instalacyjny
  language/                      pl-PL i en-GB
  lib/                           biblioteka P24, PHP 8.1+, przestrzeń nazw
tests/
  run.php                        testy jednostkowe biblioteki
```

Nazwy katalogu i klasy są narzucone przez HikaShopa. Funkcja `hikashop_import()`
ładuje wtyczkę przez `require_once` na ścieżce `plugins/hikashoppayment/<nazwa>/<nazwa>.php`
i tworzy obiekt klasy `plgHikashoppayment<Nazwa>`, z pominięciem kontenera
Joomli. Z tego powodu wtyczka nie może mieć postaci nowoczesnej wtyczki
Joomli z `services/provider.php`. Nowoczesny PHP jest natomiast w całości
biblioteki w `src/lib`, która nie zależy ani od HikaShopa, ani od Joomli
poza klientem HTTP.

## Testy

```bash
php tests/run.php
```

Testy obejmują przeliczanie kwot, kolejność kluczy w podpisach, odrzucanie
niepoprawnych powiadomień, budowanie żądania rejestracji i odczyt danych
zapisanych przy zamówieniu. Nie wymagają Joomli, HikaShopa ani composera.

Pełną procedurę testów płatności w sandboksie opisuje `playbooks/p24-testing.md`
w katalogu nadrzędnym.

## Licencja

GNU General Public License v3 lub nowsza.
