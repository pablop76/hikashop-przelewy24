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

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="hikashop_przelewy24_end">
<?php if ($errorText !== '') : ?>
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
        <h2><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_GOING_TO_GATEWAY')); ?></h2>
        <p><?php echo $escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_GOING_TO_GATEWAY_INFO')); ?></p>
        <p>
            <a id="hikashopPrzelewy24Button"
               class="btn btn-primary btn-lg hikashop_przelewy24_button"
               href="<?php echo $escape($paywallUrl); ?>"
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

            window.setTimeout(przejdzDoBramki, 400);
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
