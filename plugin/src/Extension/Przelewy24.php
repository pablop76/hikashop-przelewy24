<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 * @link        https://www.web-service.com.pl
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikService;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\BlikException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ConfigurationException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\SignatureException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Notification;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RefundService;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\RegisterRequest;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\SessionId;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\TransactionService;
use Throwable;

defined('_JEXEC') or die('Restricted access');

/*
 * HikaShop rejestruje autoload dla hikashopPaymentPlugin dopiero przy
 * pierwszym załadowaniu swojego helper.php. Autoloader Joomli potrafi
 * sięgnąć po tę klasę wcześniej, na przykład podczas instalacji wtyczki
 * w Menedżerze Rozszerzeń, i wtedy "extends hikashopPaymentPlugin"
 * kończy się błędem krytycznym.
 *
 * Guard musi stać w TYM pliku, przed deklaracją klasy: PHP odkłada
 * kompilację "class X extends Y" do wykonania tej linii, więc wystarczy
 * wcześniej doczytać helper.php.
 */
if (!class_exists('hikashopPaymentPlugin', false)) {
    $helperHikaShop = JPATH_ADMINISTRATOR . '/components/com_hikashop/helpers/helper.php';

    if (is_file($helperHikaShop)) {
        require_once $helperHikaShop;
    }
}

/**
 * Płatności Przelewy24 dla HikaShopa.
 *
 * Klasa żyje w przestrzeni nazw, zgodnie z zaleceniami Joomli 5, ale
 * HikaShop ładuje wtyczki płatności po swojemu: funkcja hikashop_import()
 * robi require_once na pliku plugins/hikashoppayment/<nazwa>/<nazwa>.php
 * i tworzy obiekt klasy plgHikashoppayment<Nazwa>. Ten most zapewnia
 * przelewy24.php, który zakłada alias na tę klasę.
 *
 * Metody on* muszą mieć sygnatury bez typów, zgodne z klasą bazową:
 * dodanie typu tam, gdzie przodek go nie ma, łamie kontrawariancję.
 * Cała otypowana logika P24 siedzi w przestrzeni Payment.
 */
class Przelewy24 extends \hikashopPaymentPlugin
{
    /**
     * Wersja wtyczki, wysyłana do P24 w nagłówku diagnostycznym.
     */
    public const VERSION = '1.0.4';

    protected $autoloadLanguage = true;

    public $multiple = true;

    public $name = 'przelewy24';

    public $doc_form = 'przelewy24';

    public $use_cache = false;

    /**
     * Waluty obsługiwane przez usługę transakcyjną P24.
     *
     * Sama obecność waluty tutaj nie wystarczy: musi być również włączona
     * na koncie sprzedawcy w P24, inaczej rejestracja transakcji zostanie
     * odrzucona z błędem opisanym w logu.
     */
    public $accepted_currencies = ['PLN', 'EUR', 'GBP', 'CZK'];

    public $features = [
        'authorize_capture' => false,
        'recurring'         => false,
        'refund'            => true,
    ];

    /**
     * Token strony płatności, odczytywany przez przelewy24_end.php.
     *
     * @var string
     */
    public $p24_token = '';

    /**
     * Klucz, pod którym trzymamy kod BLIK między kasą a złożeniem zamówienia.
     */
    public const BLIK_STATE_KEY = 'com_hikashop.przelewy24.blik_code';

    /**
     * Gotowy adres strony płatności P24, odczytywany przez widok.
     *
     * @var string
     */
    public $p24_paywall_url = '';

    /**
     * Czy płatność BLIK czeka na potwierdzenie w aplikacji banku.
     *
     * @var bool
     */
    public $p24_blik_pending = false;

    /**
     * Komunikat o odrzuconej płatności BLIK.
     *
     * @var string
     */
    public $p24_blik_error = '';

    /**
     * Komunikat dla klienta, gdy rejestracja transakcji się nie powiodła.
     *
     * @var string
     */
    public $p24_error = '';

    /**
     * Adres, pod którym klient może wrócić do kasy i spróbować ponownie.
     *
     * @var string
     */
    public $p24_retry_url = '';

    /**
     * Wartości domyślne przy zakładaniu metody płatności.
     */
    public function getPaymentDefaultValues(&$element)
    {
        $element->payment_name        = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_DEFAULT_NAME');
        $element->payment_description = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_DEFAULT_DESCRIPTION');
        $element->payment_images      = 'przelewy24';

        $element->payment_params->test_mode       = 1;
        $element->payment_params->merchant_id     = '';
        $element->payment_params->pos_id          = '';
        $element->payment_params->crc_key         = '';
        $element->payment_params->api_key         = '';
        $element->payment_params->debug           = 0;
        $element->payment_params->blik_in_shop   = 0;
        $element->payment_params->payment_method_id = 0;
        $element->payment_params->verified_status = 'confirmed';
        $element->payment_params->invalid_status  = 'cancelled';
        $element->payment_params->refund_status   = '';
    }

    /**
     * Ostrzeżenia w panelu, zanim sprzedawca odkryje brak danych
     * dopiero na pierwszym zamówieniu.
     */
    public function onPaymentConfiguration(&$element)
    {
        parent::onPaymentConfiguration($element);

        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $config = Config::fromPaymentParams($element->payment_params ?? null);

        if (!$config->isComplete()) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_KEYS_REQUIRED'), 'warning');
        }

        if (!$config->environment->isSandbox()) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PRODUCTION_WARNING'), 'notice');
        }

        // Brak wpisu IP w panelu P24 daje dokładnie ten sam błąd 401, co
        // zły klucz, więc bez tej podpowiedzi diagnoza bywa długa.
        $app->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_IP_REMINDER'), 'notice');

        // BLIK w kasie wymaga usługi, której P24 domyślnie nie włącza.
        // Bez niej płatność kodem kończy się 401, co łatwo wziąć za zły klucz.
        if ($config->blikInShop) {
            $app->enqueueMessage(
                Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_LEVEL0_REMINDER', BlikService::SUPPORT_FORM_URL),
                'notice'
            );
        }

        // Włączony wyzwalacz zwrotu oddaje klientom prawdziwe pieniądze
        // przy zwykłej zmianie statusu zamówienia. Sprzedawca musi o tym
        // wiedzieć za każdym razem, gdy otwiera tę konfigurację.
        if ($config->refundStatus !== '') {
            $app->enqueueMessage(
                Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_TRIGGER_ACTIVE', $config->refundStatus),
                'warning'
            );
        }
    }

    /**
     * Pola konfiguracji, których HikaShop nie umie narysować sam.
     */
    public function pluginConfigDisplay($fieldType, $data, $type, $paramsType, $key, $element)
    {
        if ($fieldType !== 'password') {
            return null;
        }

        $name  = 'data[' . $type . '][' . $paramsType . '][' . $key . ']';
        $value = $element->$paramsType->$key ?? '';

        return '<input type="password" autocomplete="off" name="' . $this->escape($name) . '"'
            . ' value="' . $this->escape($value) . '" size="40" />';
    }

    /**
     * Rejestruje transakcję po złożeniu zamówienia i pokazuje stronę
     * przejścia do bramki.
     */
    public function onAfterOrderConfirm(&$order, &$methods, $method_id)
    {
        parent::onAfterOrderConfirm($order, $methods, $method_id);

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        $this->p24_retry_url = $this->buildRetryUrl($order);

        $client = $this->startPayment($order, $config, $logger);

        // Kod BLIK wpisany w kasie skraca drogę: klient zostaje
        // w sklepie i potwierdza płatność w aplikacji banku.
        // Pusty kod oznacza zwykłe przejście na stronę płatności.
        if ($client !== null) {
            $blikCode = $this->takeBlikCode($config);

            if ($blikCode !== '') {
                $this->chargeBlik($order, $client, $logger, $config, $blikCode);
            }
        }

        return $this->showPage('end');
    }

    /**
     * Rejestruje transakcję w P24 i przygotowuje adres strony płatności.
     *
     * Wspólne dla złożenia zamówienia i ponowienia zapłaty. Przy błędzie
     * ustawia komunikat dla klienta w p24_error i zwraca null.
     *
     * @return ApiClient|null  klient API gotowy do dalszych wywołań albo null
     */
    protected function startPayment($order, Config $config, Logger $logger)
    {
        try {
            $config->assertComplete();

            $currencyCode  = $this->currencyCode();
            $fractionDigit = $this->currencyFractionDigits();
            $amount        = Amount::toMinorUnit($this->orderTotal($order), $fractionDigit);

            if ($amount <= 0) {
                throw new ConfigurationException('Kwota zamówienia jest zerowa lub ujemna');
            }

            // Zamówienie opłacone nie może być opłacone po raz drugi.
            // Bez tej blokady klient, który wróci do kasy, zakłada w P24
            // kolejną transakcję i płaci drugi raz za to samo.
            if ($this->isAlreadyPaid($order, $config)) {
                $logger->warning('Próba ponownej zapłaty za opłacone zamówienie', [
                    'order_id' => $order->order_id,
                    'status'   => $order->order_status ?? '',
                ]);

                $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ALREADY_PAID');
                $this->p24_retry_url = '';

                return null;
            }

            // Jeden identyfikator sesji na zamówienie, nie na próbę.
            // Ponowienie zapłaty ma prowadzić do TEJ SAMEJ transakcji
            // w P24, inaczej klient może zapłacić dwa razy.
            $sessionId = OrderPaymentData::sessionIdFor($order, (int) $order->order_id);

            // Zapisujemy próbę PRZED wysłaniem rejestracji. Gdyby P24
            // zdążyło przysłać powiadomienie, zanim wrócimy z odpowiedzią,
            // identyfikator sesji jest już przy zamówieniu.
            OrderPaymentData::startAttempt(
                (int) $order->order_id,
                $sessionId,
                $amount,
                $currencyCode,
                OrderPaymentData::getInt($order, OrderPaymentData::ATTEMPTS)
            );

            $client  = $this->buildClient($config, $logger);
            $service = new TransactionService($client, $config, $logger);

            $token = $service->register(new RegisterRequest(
                sessionId: $sessionId,
                amountInMinorUnits: $amount,
                currency: $currencyCode,
                description: Text::sprintf(
                    'PLG_HIKASHOPPAYMENT_PRZELEWY24_ORDER_DESCRIPTION',
                    $order->order_number
                ),
                email: $this->customerEmail($order),
                urlReturn: $this->buildReturnUrl($order),
                urlStatus: $this->buildNotifyUrl($order),
                country: $this->billingCountry($order),
                language: (string) ($this->locale ?: 'pl'),
                client: $this->billingName($order),
                address: $this->billingField($order, 'address_street'),
                zip: $this->billingField($order, 'address_post_code'),
                city: $this->billingField($order, 'address_city'),
                phone: $this->billingField($order, 'address_telephone'),
                // Zero zostawia wybor metody klientowi na stronie P24.
                method: $config->paymentMethodId > 0 ? $config->paymentMethodId : null
            ));

            $this->p24_token       = $token;
            $this->p24_paywall_url = $client->paywallUrl($token);

            OrderPaymentData::store((int) $order->order_id, [
                OrderPaymentData::TOKEN => $token,
            ]);

            $logger->info('Zamówienie gotowe do zapłaty', [
                'order_id'  => $order->order_id,
                'sessionId' => $sessionId,
                'kwota_gr'  => $amount,
            ]);

            return $client;
        } catch (ConfigurationException $exception) {
            $logger->error('Nie można rozpocząć płatności', [
                'order_id' => $order->order_id ?? 0,
                'powod'    => $exception->getMessage(),
            ]);

            $this->p24_error = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_CONFIG');
        } catch (ApiException $exception) {
            $logger->error('Rejestracja transakcji nie powiodła się', [
                'order_id' => $order->order_id ?? 0,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            $this->p24_error = $exception->isAuthenticationFailure()
                ? Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_AUTH')
                : Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_REGISTER');
        } catch (Throwable $exception) {
            $logger->error('Nieoczekiwany błąd przy rejestracji transakcji', [
                'order_id' => $order->order_id ?? 0,
                'powod'    => $exception->getMessage(),
            ]);

            $this->p24_error = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_REGISTER');
        }

        return null;
    }

    /**
     * Obsługuje powiadomienie z P24 wysłane na adres urlStatus.
     *
     * Powiadomienie samo w sobie nie potwierdza zapłaty. Jest sygnałem,
     * żeby zapytać P24 przez transaction/verify. Dopiero pozytywna
     * odpowiedź weryfikacji zmienia status zamówienia.
     */
    public function onPaymentNotification(&$statuses)
    {
        $app      = Factory::getApplication();

        // Ten sam punkt wejścia obsługuje przycisk ponowienia zapłaty.
        // HikaShop w wersji Starter nie ma własnego „zapłać teraz”,
        // a zadanie notify jest dostępne w każdej wersji.
        if ($app->input->getCmd('p24_action', '') === 'retry') {
            return $this->handleRetry();
        }

        $orderId  = (int) $app->input->get('order_id', 0, 'int');
        $urlToken = (string) $app->input->get('order_token', '', 'string');

        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)) {
            $this->writeToLog('P24 [BŁĄD] Powiadomienie dla nieistniejącego zamówienia | order_id=' . $orderId);

            return false;
        }

        if (!$this->loadPaymentParams($dbOrder)) {
            $this->writeToLog('P24 [BŁĄD] Brak parametrów metody płatności | order_id=' . $orderId);

            return false;
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        // Adres powiadomienia jest znany tylko P24 i nam. Sprawdzamy go,
        // zanim w ogóle sięgniemy po treść żądania.
        if (!hash_equals(md5((string) $dbOrder->order_token), $urlToken)) {
            $logger->error('Powiadomienie z błędnym znacznikiem zamówienia', ['order_id' => $orderId]);

            return false;
        }

        $this->loadOrderData($dbOrder);

        try {
            $config->assertComplete();

            $notification = Notification::fromRequestBody($this->readNotificationBody());

            $storedSessionId = OrderPaymentData::getString($dbOrder, OrderPaymentData::SESSION_ID);
            $currencyCode    = $this->currencyCode();
            $expectedAmount  = Amount::toMinorUnit(
                $dbOrder->order_full_price,
                $this->currencyFractionDigits()
            );

            $notification->assertValid($config, $storedSessionId, $expectedAmount, $currencyCode);

            $logger->info('Powiadomienie przyjęte', ['order_id' => $orderId] + $notification->toLogContext());

            // Powiadomienie potrafi przyjść więcej niż raz. Sprawdzamy to
            // przed jakimkolwiek zapisem: bez tego każde powtórzenie
            // ruszałoby stan magazynowy, wysyłało klientowi kolejny e-mail
            // i dokładało wpis do historii zamówienia.
            if ($this->isAlreadyPaid($dbOrder, $config)) {
                $logger->info('Powtórzone powiadomienie pominięte, zamówienie już opłacone', [
                    'order_id' => $orderId,
                    'status'   => $dbOrder->order_status,
                ]);

                return true;
            }

            OrderPaymentData::store($orderId, [
                OrderPaymentData::P24_ORDER_ID => $notification->p24OrderId,
                OrderPaymentData::METHOD_ID    => $notification->methodId,
            ]);

            $service = $this->buildService($config, $logger);

            $verified = $service->verify(
                $storedSessionId,
                $notification->p24OrderId,
                $expectedAmount,
                $currencyCode
            );

            if (!$verified) {
                $logger->error('Weryfikacja nie potwierdziła zapłaty', ['order_id' => $orderId]);
                $this->markInvalid($orderId, $config, $logger);

                return false;
            }

            OrderPaymentData::store($orderId, [
                OrderPaymentData::VERIFIED_AT => gmdate('c'),
            ]);

            // Zmiana statusu wysyła też powiadomienie do klienta. Gdyby
            // wysyłka się wywróciła, zapłata i tak jest zaksięgowana,
            // więc nie wolno odpowiedzieć P24, że obsługa się nie udała.
            // Inaczej bramka ponawia powiadomienie bez potrzeby.
            try {
                $this->modifyOrder($orderId, $config->verifiedStatus, true, true);
            } catch (Throwable $exception) {
                $logger->error('Błąd przy zmianie statusu zamówienia', [
                    'order_id' => $orderId,
                    'powod'    => $exception->getMessage(),
                ]);
            }

            // O powodzeniu decyduje stan zamówienia w bazie, nie to,
            // czy wszystkie czynności poboczne przeszły bez potknięcia.
            $paid = $this->isAlreadyPaid($this->getOrder($orderId), $config);

            if (!$paid) {
                $logger->error('Zapłata potwierdzona, ale status zamówienia się nie zmienił', [
                    'order_id' => $orderId,
                    'oczekiwany_status' => $config->verifiedStatus,
                ]);

                return false;
            }

            $logger->info('Zamówienie oznaczone jako opłacone', [
                'order_id' => $orderId,
                'status'   => $config->verifiedStatus,
            ]);

            return true;
        } catch (SignatureException $exception) {
            // Niezgodność podpisu, sesji, kwoty lub waluty. Zamówienia
            // nie wolno wtedy ruszyć.
            $logger->error('Powiadomienie odrzucone', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        } catch (ApiException $exception) {
            // P24 zwraca HTTP 400 także wtedy, gdy transakcja po prostu
            // nie została opłacona. To nie jest awaria sklepu.
            $logger->error('Weryfikacja transakcji nie powiodła się', [
                'order_id' => $orderId,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            // Status zmieniamy wyłącznie wtedy, gdy P24 JAWNIE odmówiło,
            // czyli odpowiedziało własnym kodem błędu. Zerwane połączenie,
            // przekroczony czas albo sieczka zamiast JSON-a są niejawne:
            // transakcja mogła zostać opłacona, a tylko odpowiedź do nas
            // nie dotarła. Wtedy zostawiamy status w spokoju i czekamy
            // na kolejne powiadomienie.
            if ($exception->getApiCode() !== null) {
                $this->markInvalid($orderId, $config, $logger);
            }

            return false;
        } catch (Throwable $exception) {
            $logger->error('Nieoczekiwany błąd przy obsłudze powiadomienia', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Pole na kod BLIK obok metody płatności w kasie.
     *
     * HikaShop wstawia tu dowolny HTML. Metody nie oznaczamy jako
     * wymagającej danych: pusty kod ma prowadzić na stronę płatności
     * P24, gdzie klient wybierze cokolwiek innego.
     */
    public function needCC(&$method)
    {
        $config = Config::fromPaymentParams($this->payment_params ?? ($method->payment_params ?? null));

        if (!$config->blikInShop) {
            return;
        }

        $wpisany = (string) Factory::getApplication()->getUserState(self::BLIK_STATE_KEY, '');

        // Bez tej flagi HikaShop dokłada pod polem własny przycisk „Wyślij”.
        // Zapisuje on tylko blok płatności i nie składa zamówienia, więc
        // klient klika go, widzi kręciołek i myśli, że zapłacił. Pole leży
        // w formularzu kasy, więc kod i tak trafia do nas razem z przyciskiem
        // składającym zamówienie.
        $method->custom_html_no_btn = true;

        $method->custom_html = '<div class="hikashop_przelewy24_blik">'
            . '<label for="hikashop_przelewy24_blik_code">'
            . $this->escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_CODE')) . '</label> '
            . '<input type="text" id="hikashop_przelewy24_blik_code" name="hikashop_przelewy24_blik_code"'
            . ' class="hikashop_przelewy24_blik_code inputbox" inputmode="numeric" autocomplete="off"'
            . ' pattern="[0-9 -]*" maxlength="8" size="8" value="' . $this->escape($wpisany) . '" />'
            . '<small class="hikashop_przelewy24_blik_hint">'
            . $this->escape(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_HINT'))
            . '</small></div>';
    }

    /**
     * Odczytuje kod BLIK wpisany w kasie i sprawdza jego kształt.
     */
    public function onPaymentSave(&$cart, &$rates, &$payment_id)
    {
        $metoda = parent::onPaymentSave($cart, $rates, $payment_id);

        $app  = Factory::getApplication();
        $code = BlikService::normaliseCode((string) $app->input->getString('hikashop_przelewy24_blik_code', ''));

        // Kod trzymamy w stanie sesji, nie w bazie: jest jednorazowy
        // i ważny około dwóch minut, więc nie ma czego przechowywać.
        $app->setUserState(self::BLIK_STATE_KEY, $code);

        if ($code === '' || !\is_object($metoda) || ($metoda->payment_type ?? '') !== $this->name) {
            return $metoda;
        }

        if (!BlikService::isValidCode($code)) {
            $app->enqueueMessage(Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_CODE_INVALID'), 'error');

            return false;
        }

        return $metoda;
    }

    /**
     * Pobiera kod BLIK ze stanu sesji i od razu go stamtąd usuwa.
     *
     * Kod jest jednorazowy. Zostawienie go w sesji groziłoby użyciem
     * przy następnym zamówieniu, gdy jest już dawno nieważny.
     */
    protected function takeBlikCode(Config $config)
    {
        $app = Factory::getApplication();

        if (!$config->blikInShop) {
            return '';
        }

        $code = (string) $app->getUserState(self::BLIK_STATE_KEY, '');
        $app->setUserState(self::BLIK_STATE_KEY, '');

        return BlikService::normaliseCode($code);
    }

    /**
     * Obciąża zamówienie kodem BLIK.
     *
     * Nie rzuca dalej: nieudany BLIK nie może przerwać składania
     * zamówienia. Klient dostaje komunikat i zostaje mu zwykła droga
     * przez stronę płatności P24.
     */
    protected function chargeBlik($order, ApiClient $client, Logger $logger, Config $config, $blikCode)
    {
        try {
            $p24OrderId = (new BlikService($client, $logger))->chargeByCode($this->p24_token, $blikCode);

            $this->p24_blik_pending = true;

            OrderPaymentData::store((int) $order->order_id, [
                OrderPaymentData::P24_ORDER_ID => $p24OrderId,
            ]);

            $logger->info('BLIK czeka na potwierdzenie w aplikacji banku', [
                'order_id'  => $order->order_id,
                'p24_order' => $p24OrderId,
            ]);
        } catch (BlikException $exception) {
            $logger->error('Płatność BLIK odrzucona', [
                'order_id' => $order->order_id ?? 0,
                'powod'    => $exception->getReason()->value,
            ]);

            // Zużytego kodu nie wolno wysłać drugi raz: obciążenie mogło
            // dojść do skutku, a tylko odpowiedź do nas nie dotarła.
            $this->p24_blik_pending = $exception->isCodeConsumed();

            if (!$this->p24_blik_pending) {
                $this->p24_blik_error = Text::_($exception->getReason()->languageKey());
            }
        } catch (ApiException $exception) {
            $logger->error('Nie udało się obciążyć kodem BLIK', [
                'order_id' => $order->order_id ?? 0,
                'http'     => $exception->getHttpStatus(),
            ]);

            $this->p24_blik_error = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_BLIK_ERROR_GENERAL_ERROR');
        }
    }

    /**
     * Zwraca pieniądze, gdy sprzedawca nada zamówieniu ustalony status.
     *
     * HikaShop nie wywołuje onOrderPaymentRefund() z panelu, więc zwrot
     * podpinamy pod własne zdarzenie HikaShopa: zmianę zamówienia.
     * Wyzwalacz jest domyślnie wyłączony i wymaga wskazania statusu
     * w konfiguracji metody płatności.
     *
     * @param  object  $order       zmieniane zamówienie
     * @param  bool    $send_email  czy HikaShop wyśle powiadomienie
     */
    public function onAfterOrderUpdate(&$order, &$send_email)
    {
        // Zapis danych zwrotu przy zamówieniu sam wywołuje to zdarzenie
        // ponownie. Bez tej blokady powstałaby pętla, a przy niej kolejne
        // zgłoszenia zwrotu.
        static $wTrakcie = [];

        $orderId = (int) ($order->order_id ?? 0);

        if ($orderId <= 0 || isset($wTrakcie[$orderId])) {
            return;
        }

        $nowyStatus = (string) ($order->order_status ?? '');

        if ($nowyStatus === '') {
            return;
        }

        // Zdarzenie dostajemy dla KAŻDEGO zamówienia w sklepie, także
        // opłaconego inną metodą. Pełne dane bierzemy z bazy, bo obiekt
        // zdarzenia zawiera zwykle tylko zmienione pola.
        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder) || ($dbOrder->order_payment_method ?? '') !== $this->name) {
            return;
        }

        if (!$this->loadPaymentParams($dbOrder)) {
            return;
        }

        $config = Config::fromPaymentParams($this->payment_params);

        if ($config->refundStatus === '' || $nowyStatus !== $config->refundStatus) {
            return;
        }

        // Zwrot zgłaszamy raz. Powtórzenie oddałoby pieniądze drugi raz.
        if (OrderPaymentData::getString($dbOrder, OrderPaymentData::REFUND_REQUEST_ID) !== '') {
            return;
        }

        $wTrakcie[$orderId] = true;

        try {
            $zgloszony = $this->onOrderPaymentRefund($dbOrder, null);

            $this->informAboutRefund($orderId, $dbOrder, $zgloszony);
        } finally {
            unset($wTrakcie[$orderId]);
        }
    }

    /**
     * Mówi sprzedawcy wprost, co się właśnie stało z pieniędzmi.
     */
    protected function informAboutRefund($orderId, $order, $zgloszony)
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        if (!$zgloszony) {
            $app->enqueueMessage(
                Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_FAILED_NOTICE', $order->order_number ?? $orderId),
                'error'
            );

            return;
        }

        $kwota = Amount::fromMinorUnit(
            OrderPaymentData::getInt($order, OrderPaymentData::REFUND_AMOUNT),
            $this->currencyFractionDigits()
        );

        $app->enqueueMessage(
            Text::sprintf(
                'PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_SENT_NOTICE',
                number_format($kwota, 2, ',', ' '),
                $this->currencyCode(),
                $order->order_number ?? $orderId
            ),
            'warning'
        );
    }

    /**
     * Zwrot pełny lub częściowy.
     *
     * Uwaga: HikaShop 5.1.2 nie wywołuje tej metody z żadnego miejsca
     * w panelu, choć deklaruje ją w klasie bazowej wtyczek płatności.
     * Implementacja jest zgodna z tym interfejsem, więc zadziała tam,
     * gdzie HikaShop ją podepnie, i daje się wywołać z własnego kodu.
     *
     * @param  object      $order  zamówienie HikaShopa
     * @param  float|null  $total  kwota zwrotu, pusta oznacza całość
     *
     * @return bool  czy P24 przyjęło zgłoszenie zwrotu
     */
    public function onOrderPaymentRefund(&$order, $total)
    {
        if (empty($order->order_id)) {
            return false;
        }

        $orderId = (int) $order->order_id;

        if (!$this->loadPaymentParams($order)) {
            $this->writeToLog('P24 [BŁĄD] Zwrot: brak parametrów metody płatności | order_id=' . $orderId);

            return false;
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        try {
            $config->assertComplete();

            $this->loadOrderData($order);

            $sessionId  = OrderPaymentData::getString($order, OrderPaymentData::SESSION_ID);
            $p24OrderId = OrderPaymentData::getInt($order, OrderPaymentData::P24_ORDER_ID);

            if ($sessionId === '' || $p24OrderId <= 0) {
                // Bez identyfikatora transakcji nadanego przez P24 nie ma
                // czego zwracać. Taki stan oznacza, że zapłata nigdy nie
                // została potwierdzona powiadomieniem.
                $logger->error('Zwrot niemożliwy, brak potwierdzonej transakcji P24', [
                    'order_id' => $orderId,
                ]);

                return false;
            }

            $fractionDigits = $this->currencyFractionDigits();
            $paidAmount     = OrderPaymentData::getInt($order, OrderPaymentData::AMOUNT);

            $amount = empty($total)
                ? $paidAmount
                : Amount::toMinorUnit($total, $fractionDigits);

            if ($amount <= 0) {
                $logger->error('Zwrot niemożliwy, kwota jest zerowa', ['order_id' => $orderId]);

                return false;
            }

            if ($paidAmount > 0 && $amount > $paidAmount) {
                $logger->error('Zwrot niemożliwy, kwota przekracza zapłaconą', [
                    'order_id'   => $orderId,
                    'zwrot_gr'   => $amount,
                    'zaplata_gr' => $paidAmount,
                ]);

                return false;
            }

            $client  = new ApiClient($config, $logger, self::VERSION, HIKASHOP_LIVE);
            $service = new RefundService($client, $config, $logger);

            $wynik = $service->refund(
                $sessionId,
                $p24OrderId,
                $amount,
                Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_REFUND_DESCRIPTION', $order->order_number ?? $orderId),
                $this->buildRefundNotifyUrl($order)
            );

            $status = $wynik['status'];

            OrderPaymentData::store($orderId, [
                OrderPaymentData::REFUND_REQUEST_ID => $wynik['requestId'],
                OrderPaymentData::REFUND_AMOUNT     => $amount,
                OrderPaymentData::REFUND_STATUS     => $status?->value ?? 0,
            ]);

            if ($status !== null && $status->isRejected()) {
                $logger->error('P24 odrzuciło zwrot', [
                    'order_id'  => $orderId,
                    'requestId' => $wynik['requestId'],
                ]);

                return false;
            }

            $logger->info('Zwrot zgłoszony', [
                'order_id'  => $orderId,
                'kwota_gr'  => $amount,
                'stan'      => $status?->name ?? 'nieznany',
            ]);

            return true;
        } catch (ConfigurationException $exception) {
            $logger->error('Zwrot niemożliwy, niekompletna konfiguracja', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        } catch (ApiException $exception) {
            $logger->error('Zgłoszenie zwrotu nie powiodło się', [
                'order_id' => $orderId,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        } catch (Throwable $exception) {
            $logger->error('Nieoczekiwany błąd przy zwrocie', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Adres, na który P24 ma przysłać powiadomienie o stanie zwrotu.
     */
    protected function buildRefundNotifyUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&p24_notify=refund'
            . '&tmpl=component'
            . '&order_id=' . (int) $order->order_id
            . '&order_token=' . md5((string) $order->order_token);
    }

    /**
     * Surowa treść powiadomienia przysłanego przez P24.
     *
     * Wydzielone do osobnej metody, żeby testy mogły podstawić własną
     * treść bez udawania żądania HTTP.
     */
    protected function readNotificationBody()
    {
        return (string) file_get_contents('php://input');
    }

    /**
     * Nadaje zamówieniu status nieudanej płatności.
     *
     * Wołane wyłącznie wtedy, gdy P24 definitywnie odmówiło: albo
     * odpowiedziało, że transakcja nie jest potwierdzona, albo odrzuciło
     * weryfikację kodem HTTP. Zerwane połączenie odmową nie jest.
     *
     * Zamówienia już opłaconego nie ruszamy nigdy. W HikaShopie status
     * anulowania potrafi zwrócić towar na stan, więc pomyłka w tę stronę
     * byłaby kosztowna.
     */
    protected function markInvalid($orderId, Config $config, Logger $logger)
    {
        if ($config->invalidStatus === '') {
            return;
        }

        $order = $this->getOrder($orderId);

        if (empty($order)) {
            return;
        }

        $current = (string) ($order->order_status ?? '');

        if ($current === $config->invalidStatus) {
            return;
        }

        if ($this->isAlreadyPaid($order, $config)) {
            $logger->warning('Nie zmieniam statusu: zamówienie jest już opłacone', [
                'order_id' => $orderId,
                'status'   => $current,
            ]);

            return;
        }

        try {
            $this->modifyOrder($orderId, $config->invalidStatus, true, false);

            $logger->info('Zamówienie oznaczone jako nieopłacone', [
                'order_id' => $orderId,
                'status'   => $config->invalidStatus,
            ]);
        } catch (Throwable $exception) {
            $logger->error('Nie udało się zmienić statusu na nieudaną płatność', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Czy zamówienie jest już w statusie oznaczającym zapłatę.
     */
    protected function isAlreadyPaid($order, Config $config)
    {
        $current = (string) ($order->order_status ?? '');

        if ($current === '') {
            return false;
        }

        return $current === $config->verifiedStatus;
    }

    protected function buildLogger(Config $config)
    {
        return new Logger($this->name, $config->debug, $config->secrets());
    }

    protected function buildClient(Config $config, Logger $logger)
    {
        return new ApiClient($config, $logger, self::VERSION, HIKASHOP_LIVE);
    }

    protected function buildService(Config $config, Logger $logger)
    {
        $client = $this->buildClient($config, $logger);

        return new TransactionService($client, $config, $logger);
    }

    /**
     * Adres, na który P24 wysyła powiadomienie o transakcji.
     */
    protected function buildNotifyUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&tmpl=component'
            . '&lang=' . urlencode((string) $this->locale)
            . '&order_id=' . (int) $order->order_id
            . '&order_token=' . md5((string) $order->order_token);
    }

    /**
     * Adres, pod który P24 odsyła klienta po zakończeniu płatności.
     *
     * Nie potwierdza zapłaty. Służy wyłącznie pokazaniu wyniku.
     */
    protected function buildReturnUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end'
            . '&order_id=' . (int) $order->order_id
            . $this->url_itemid;
    }

    /**
     * Adres ponowienia zapłaty za TO SAMO zamówienie.
     *
     * Wcześniej przycisk prowadził do kasy, ale koszyk jest już wtedy
     * pusty, bo zamówienie powstało. Ponowienie z panelu klienta
     * (order&task=pay) HikaShop daje dopiero od wersji Essential.
     * Dlatego ponowienie obsługuje sama wtyczka.
     */
    protected function buildRetryUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&p24_action=retry'
            . '&order_id=' . (int) $order->order_id
            . '&p24_retry=' . $this->retryToken($order)
            . (string) ($this->url_itemid ?? '');
    }

    /**
     * Znacznik adresu ponowienia.
     *
     * Wyprowadzony z tajnego order_token zamówienia, więc adresu nie da
     * się ułożyć dla cudzego zamówienia. Celowo inny niż znacznik
     * powiadomień P24, żeby jeden adres nie otwierał drugiego.
     */
    protected function retryToken($order)
    {
        return hash_hmac('sha256', 'p24-retry|' . (int) $order->order_id, (string) ($order->order_token ?? ''));
    }

    /**
     * Ponawia zapłatę za zamówienie, którego płatność nie ruszyła.
     *
     * Rejestruje transakcję z tym samym identyfikatorem sesji, więc P24
     * prowadzi do tej samej transakcji, a nie zakłada drugiej. Po udanej
     * rejestracji przekierowuje klienta prosto na stronę płatności.
     */
    protected function handleRetry()
    {
        $app     = Factory::getApplication();
        $orderId = (int) $app->input->get('order_id', 0, 'int');
        $token   = (string) $app->input->get('p24_retry', '', 'string');

        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadPaymentParams($dbOrder)
            || !hash_equals($this->retryToken($dbOrder), $token)
        ) {
            $this->writeToLog('P24 [BŁĄD] Odrzucone ponowienie płatności | order_id=' . $orderId);

            $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_INVALID');
            $this->p24_retry_url = '';

            return $this->renderPage('end');
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        $this->loadOrderData($dbOrder);

        // Zamówienia zwróconego albo anulowanego nie wolno opłacić
        // ponownie: towar mógł już wrócić na stan.
        $status = (string) ($dbOrder->order_status ?? '');

        if (\in_array($status, array_filter([$config->refundStatus, 'cancelled', 'refunded']), true)) {
            $logger->warning('Ponowienie zapłaty za zamknięte zamówienie', [
                'order_id' => $orderId,
                'status'   => $status,
            ]);

            $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_UNAVAILABLE');
            $this->p24_retry_url = '';

            return $this->renderPage('end');
        }

        $this->p24_retry_url = $this->buildRetryUrl($dbOrder);

        $logger->info('Klient ponawia zapłatę', ['order_id' => $orderId]);

        if ($this->startPayment($dbOrder, $config, $logger) === null) {
            return $this->renderPage('end');
        }

        $this->redirectTo($this->p24_paywall_url);

        return true;
    }

    /**
     * Zwraca widok wtyczki jako tekst.
     *
     * Zadanie notify HikaShopa przechwytuje wszystko, co zostanie wypisane,
     * i odkłada to do dziennika. Na ekran trafia tylko zwrócony tekst.
     */
    protected function renderPage($name)
    {
        ob_start();
        $this->showPage($name);

        return (string) ob_get_clean();
    }

    protected function redirectTo($url)
    {
        Factory::getApplication()->redirect($url);
    }

    /**
     * Adres e-mail klienta dla P24.
     *
     * Najpierw właściciel zamówienia, dopiero potem zalogowany użytkownik:
     * przy ponowieniu zapłaty przeglądarka nie musi mieć sesji klienta.
     */
    protected function customerEmail($order)
    {
        $email = (string) ($order->customer->user_email ?? '');

        if ($email === '' && !empty($order->order_user_id)) {
            $customer = hikashop_get('class.user')->get((int) $order->order_user_id);
            $email    = (string) ($customer->user_email ?? '');
        }

        if ($email === '') {
            $email = (string) ($this->user->user_email ?? '');
        }

        return $email;
    }

    protected function currencyCode()
    {
        return strtoupper((string) ($this->currency->currency_code ?? 'PLN'));
    }

    /**
     * Liczba miejsc po przecinku waluty zamówienia.
     */
    protected function currencyFractionDigits()
    {
        $digits = $this->currency->currency_locale['int_frac_digits'] ?? 2;

        if (!is_numeric($digits)) {
            return 2;
        }

        $digits = (int) $digits;

        return ($digits >= 0 && $digits <= 4) ? $digits : 2;
    }

    /**
     * Wartość zamówienia brutto.
     */
    protected function orderTotal($order)
    {
        if (isset($order->cart->full_total->prices[0]->price_value_with_tax)) {
            return $order->cart->full_total->prices[0]->price_value_with_tax;
        }

        return $order->order_full_price ?? 0;
    }

    protected function billingField($order, $field)
    {
        return (string) ($order->cart->billing_address->$field ?? '');
    }

    protected function billingName($order)
    {
        $first = $this->billingField($order, 'address_firstname');
        $last  = $this->billingField($order, 'address_lastname');

        return trim($first . ' ' . $last);
    }

    protected function billingCountry($order)
    {
        $code = $order->cart->billing_address->address_country->zone_code_2 ?? '';

        return $code !== '' ? strtoupper((string) $code) : 'PL';
    }

    /**
     * Bezpieczne wypisanie wartości w atrybucie HTML.
     */
    protected function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
