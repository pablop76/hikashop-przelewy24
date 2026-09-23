# Przelewy24 dla HikaShop

Wtyczka płatności Przelewy24 dla sklepu HikaShop działającego na Joomli 5 i 6.

## Wymagania

| Składnik | Wersja |
|---|---|
| Joomla | 5.0 lub nowsza (zgodna z 6.x) |
| HikaShop | 5.0 lub nowsza |
| PHP | 8.1 minimum, zalecane 8.3 |
| Konto | Przelewy24 z dostępem do REST API |

## Stan prac

Wtyczka powstaje etapami. Rdzeń integracji jest gotowy i pokryty testami.

| Etap | Stan |
|---|---|
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

**Każda próba zapłaty ma własny `sessionId`.** P24 nie przyjmuje dwa razy tego
samego identyfikatora sesji. Wysyłanie w tym polu numeru zamówienia sprawia,
że druga próba zapłaty za to samo zamówienie kończy się błędem, a klient nie
ma jak ponowić płatności. Powiązanie z zamówieniem trzymamy po stronie sklepu.

**Powiadomienia są weryfikowane.** Sprawdzamy podpis, identyfikator sprzedawcy,
zgodność sesji z zapisaną przy zamówieniu oraz kwotę i walutę. Niezgodność
w którymkolwiek z tych punktów oznacza, że zamówienia nie wolno ruszyć.

**Sekrety nie trafiają do logu.** `Logger` wymazuje wartości klucza API i CRC
zarówno z pól kontekstu, jak i z treści komunikatów.

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
