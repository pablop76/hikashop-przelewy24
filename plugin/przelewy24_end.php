<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 *
 * Strona przejścia na stronę płatności P24.
 *
 * Plik jest wczytywany przez hikashopPlugin::showPage(), więc $this
 * wskazuje na obiekt wtyczki. Można go nadpisać w szablonie, kładąc
 * własną wersję w templates/<szablon>/hikashoppayment/przelewy24_end.php
 *
 * Przycisk w tym widoku NIGDY nie jest wyłączany. Wyłączanie go na czas
 * przekierowania jest częstym pomysłem i częstym błędem: gdy klient
 * wróci z bramki przyciskiem „wstecz”, przeglądarka odtwarza stronę
 * z pamięci podręcznej razem ze stanem pól, skrypty się nie wykonują,
 * a klient zostaje z szarym, nieklikalnym przyciskiem i bez możliwości
 * ponowienia płatności.
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die('Restricted access');

$paywallUrl = (string) $this->p24_paywall_url;
$errorText  = (string) $this->p24_error;
$retryUrl   = (string) $this->p24_retry_url;
$blikPending = (bool) $this->p24_blik_pending;
$blikError   = (string) $this->p24_blik_error;
$blikStatusUrl = (string) ($this->p24_blik_status_url ?? '');
$blikCheckUrl  = (string) ($this->p24_blik_check_url ?? '');
$paidNotice  = (string) ($this->p24_paid_notice ?? '');
$unpaidNotice = (string) ($this->p24_unpaid_notice ?? '');

// Przycisk prowadzi przez sklep, a nie wprost na stronę płatności P24.
// Klient klika go zwykle po powrocie z bramki przyciskiem „wstecz”, czyli
// po próbie, która się nie udała. Zapisany tu adres strony płatności jest
// wtedy martwy: P24 od razu odsyła z niego z powrotem do sklepu. Sklep
// zakłada nową transakcję i dopiero na nią przekierowuje.
$buttonUrl = $retryUrl !== '' ? $retryUrl : $paywallUrl;

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="hikashop_przelewy24_end">
<?php if ($blikPending) : ?>
    <div class="hikashop_przelewy24_blik_waiting" aria-live="polite">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_WAITING')); ?></h2>
        <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_WAITING_INFO')); ?></p>
        <p class="hikashop_przelewy24_note">
            <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_WAITING_AFTER')); ?>
        </p>
    <?php if ($blikCheckUrl !== '') : ?>
        <p>
            <a class="btn btn-secondary hikashop_przelewy24_blik_check" href="<?php echo $escape($blikCheckUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_CHECK_NOW')); ?>
            </a>
        </p>
    <?php endif; ?>
    </div>
    <?php if ($blikStatusUrl !== '') : ?>
    <?php
    // Strona sama sprawdza, jak skończyła się płatność, i przechodzi dalej:
    // na podziękowanie albo na stronę z powodem odrzucenia i ponowieniem.
    // Do 1.0.8 stała w miejscu i klient po potwierdzeniu w aplikacji dalej
    // czytał, że ma potwierdzić.
    //
    // Skrypt o niczym nie decyduje. Pyta sklep, a sklep czyta zamówienie
    // z bazy albo pyta P24 z serwera. Zapłatę nadal potwierdza wyłącznie
    // transaction/verify.
    ?>
    <script type="text/javascript">
    (function () {
        'use strict';

        var adresStanu = <?php echo json_encode($blikStatusUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var adresSprawdzenia = <?php echo json_encode($blikCheckUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Kod BLIK jest ważny dwie minuty, bank dolicza chwilę na odpowiedź.
        var LIMIT_MS = 150000;
        // Sklep czytamy co dwie sekundy: to tylko odczyt zamówienia z bazy.
        var ODSTEP_MS = 2000;
        // P24 pytamy rzadziej i dopiero po chwili. Zwykle zapłatę potwierdza
        // powiadomienie z P24 i pytanie okazuje się zbędne.
        var PIERWSZE_PYTANIE_P24_MS = 8000;
        var ODSTEP_P24_MS = 10000;

        var poczatek = Date.now();
        var ostatniePytanieP24 = 0;
        var zakonczone = false;
        var wToku = false;

        function przejdz(adres) {
            if (zakonczone || !adres) {
                return;
            }

            zakonczone = true;
            window.location.href = adres;
        }

        function odczytaj(tekst) {
            // Odpowiedź to JSON. Wyłuskanie obiektu wyrażeniem chroni przed
            // witryną, która coś do odpowiedzi dokleja.
            var trafienie = /\{"state":"[a-z]+"[^{}]*\}/.exec(tekst || '');

            if (!trafienie) {
                return null;
            }

            try {
                return JSON.parse(trafienie[0]);
            } catch (blad) {
                return null;
            }
        }

        function zaplanuj() {
            if (!zakonczone) {
                window.setTimeout(sprawdz, ODSTEP_MS);
            }
        }

        function sprawdz() {
            if (zakonczone || wToku) {
                return;
            }

            var minelo = Date.now() - poczatek;

            if (minelo > LIMIT_MS) {
                // Strona powrotu zapyta P24 ostatni raz i pokaże właściwy
                // komunikat: podziękowanie albo brak potwierdzenia z ponowieniem.
                przejdz(adresSprawdzenia);

                return;
            }

            var adres = adresStanu;

            if (minelo >= PIERWSZE_PYTANIE_P24_MS && Date.now() - ostatniePytanieP24 >= ODSTEP_P24_MS) {
                ostatniePytanieP24 = Date.now();
                adres += '&p24_ask=1';
            }

            wToku = true;

            var zadanie = new XMLHttpRequest();

            zadanie.open('GET', adres + '&_=' + Date.now(), true);
            zadanie.timeout = 20000;
            zadanie.onloadend = function () {
                wToku = false;

                var odpowiedz = zadanie.status === 200 ? odczytaj(zadanie.responseText) : null;

                if (odpowiedz && odpowiedz.redirect && odpowiedz.state !== 'waiting') {
                    przejdz(odpowiedz.redirect);

                    return;
                }

                // Błędny znacznik się nie naprawi, więc nie ma po co pytać dalej.
                if (odpowiedz && odpowiedz.state === 'invalid') {
                    zakonczone = true;

                    return;
                }

                zaplanuj();
            };
            zadanie.send();
        }

        window.addEventListener('pageshow', function (zdarzenie) {
            // Powrót „wstecz” z podziękowania odtwarza stronę z pamięci
            // przeglądarki razem ze stanem skryptu, więc zaczynamy od nowa.
            if (zdarzenie.persisted) {
                zakonczone = false;
                wToku = false;
                poczatek = Date.now();
                ostatniePytanieP24 = 0;
            }

            zaplanuj();
        });
    })();
    </script>
    <?php endif; ?>
<?php elseif ($paidNotice !== '') : ?>
    <?php
    // Za zamówienie już zapłacono: bez przycisku płatności i bez nagłówka
    // o błędzie. Klient, który zapłacił, ma przeczytać, że wszystko gra.
    ?>
    <div class="hikashop_przelewy24_paid">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_TITLE')); ?></h2>
        <p><?php echo $escape($paidNotice); ?></p>
    </div>
<?php elseif ($unpaidNotice !== '') : ?>
    <?php
    // Klient wrócił ze strony płatności P24, a wpłaty nie ma. To nie jest
    // podziękowanie za zamówienie: ma zobaczyć, że nie zapłacił, i móc
    // zapłacić jeszcze raz.
    ?>
    <div class="hikashop_przelewy24_unpaid">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETURN_UNPAID_TITLE')); ?></h2>
        <p><?php echo $escape($unpaidNotice); ?></p>
    <?php if ($retryUrl !== '') : ?>
        <p>
            <a class="btn btn-primary btn-lg hikashop_przelewy24_retry" href="<?php echo $escape($retryUrl); ?>" rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BACK_TO_CHECKOUT')); ?>
            </a>
        </p>
    <?php endif; ?>
        <p class="hikashop_przelewy24_note">
            <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ORDER_KEPT')); ?>
        </p>
    </div>
<?php elseif ($errorText !== '') : ?>
    <div class="hikashop_przelewy24_error">
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAYMENT_NOT_STARTED')); ?></h2>
        <p><?php echo $escape($errorText); ?></p>
    <?php if ($retryUrl !== '') : ?>
        <p>
            <a class="btn btn-primary hikashop_przelewy24_retry" href="<?php echo $escape($retryUrl); ?>">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BACK_TO_CHECKOUT')); ?>
            </a>
        </p>
    <?php endif; ?>
        <p class="hikashop_przelewy24_note">
            <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ORDER_KEPT')); ?>
        </p>
    </div>
<?php else : ?>
    <div class="hikashop_przelewy24_redirect">
    <?php if ($blikError !== '') : ?>
        <div class="hikashop_przelewy24_blik_error">
            <p><strong><?php echo $escape($blikError); ?></strong></p>
            <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_OR_PAYWALL')); ?></p>
        </div>
    <?php endif; ?>
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_GOING_TO_GATEWAY')); ?></h2>
        <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_GOING_TO_GATEWAY_INFO')); ?></p>
        <p>
            <a id="hikashopPrzelewy24Button"
               class="btn btn-primary btn-lg hikashop_przelewy24_button"
               href="<?php echo $escape($buttonUrl); ?>"
               rel="nofollow">
                <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_GO_TO_GATEWAY')); ?>
            </a>
        </p>
        <p class="hikashop_przelewy24_note">
            <?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_MANUAL_HINT')); ?>
        </p>
    </div>

    <script type="text/javascript">
    (function () {
        'use strict';

        var adres = <?php echo json_encode($paywallUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Po odrzuconej płatności BLIK nie przekierowujemy samoczynnie:
        // klient musi zdążyć przeczytać, czemu nie wyszło.
        var samoczynnie = <?php echo $blikError === '' ? 'true' : 'false'; ?>;

        if (!adres) {
            return;
        }

        // Przekierowanie samoczynne tylko przy pierwszym wyświetleniu.
        // Gdy klient wróci "wstecz" z bramki, strona pochodzi z pamięci
        // podręcznej przeglądarki i ma wtedy zostać taka, jaka jest:
        // z zywym przyciskiem, żeby mógł zdecydować sam.
        var juzPrzekierowano = false;

        function przejdzDoBramki() {
            if (juzPrzekierowano) {
                return;
            }

            juzPrzekierowano = true;
            window.location.href = adres;
        }

        window.addEventListener('pageshow', function (zdarzenie) {
            if (zdarzenie.persisted) {
                // Powrót z pamięci podręcznej: odblokowujemy możliwość
                // ponownego przejścia, ale nie robimy tego za klienta.
                juzPrzekierowano = false;

                return;
            }

            if (samoczynnie) {
                window.setTimeout(przejdzDoBramki, 400);
            }
        });

        var przycisk = document.getElementById('hikashopPrzelewy24Button');

        if (przycisk) {
            przycisk.addEventListener('click', function () {
                juzPrzekierowano = true;
            });
        }
    })();
    </script>
<?php endif; ?>
</div>
