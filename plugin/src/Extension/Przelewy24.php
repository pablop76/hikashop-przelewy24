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
use Joomla\CMS\Uri\Uri;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Amount;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\ApiClient;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikError;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikNotification;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\BlikService;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Config;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\BlikException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ConfigurationException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\SignatureException;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Logger;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Notification;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\OrderPaymentData;
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
    public const VERSION = '1.0.10';

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

    /**
     * Zwrotów wtyczka nie obsługuje i jest to decyzja, nie brak.
     *
     * HikaShop nie ma w panelu czynności „zwróć pieniądze”, więc zwrot
     * dałoby się podpiąć tylko pod zmianę statusu zamówienia. Status
     * zmienia się rutynowo, także hurtem i przez akcje masowe, a zwrotu
     * nie da się cofnąć. Zwroty robi się w panelu Przelewy24.
     */
    public $features = [
        'authorize_capture' => false,
        'recurring'         => false,
        'refund'            => false,
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
     * Adres, pod którym strona oczekiwania pyta sklep o wynik płatności BLIK.
     *
     * @var string
     */
    public $p24_blik_status_url = '';

    /**
     * Adres, na który strona oczekiwania przechodzi, gdy czas minie albo
     * klient sam chce sprawdzić, czy płatność doszła.
     *
     * @var string
     */
    public $p24_blik_check_url = '';

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
     * Informacja dla klienta, że za zamówienie już zapłacono.
     *
     * To nie jest błąd, więc ma w widoku osobne miejsce: klient, który
     * zapłacił, nie powinien czytać „nie udało się rozpocząć płatności”.
     *
     * @var string
     */
    public $p24_paid_notice = '';

    /**
     * Informacja dla klienta, który wrócił z bramki bez potwierdzonej zapłaty.
     *
     * Osobna od p24_error: tamten mówi, że płatności nie udało się zacząć,
     * a tu klient był już na stronie P24 i wrócił bez wpłaty.
     *
     * @var string
     */
    public $p24_unpaid_notice = '';

    /**
     * Dopisek do adresów zadania notify, które otwiera klient, a nie P24.
     *
     * Wtyczka systemowa HikaShopa przechwytuje zadanie notify, zanim Joomla
     * zacznie budować stronę, i oddaje wynik bez szablonu witryny. Dla
     * powiadomień z P24 to dobrze. Klient wracający z bramki albo ponawiający
     * zapłatę zobaczyłby jednak goły tekst na białym tle. Ten parametr każe
     * wtyczce systemowej odpuścić i żądanie obsługuje komponent, w szablonie.
     */
    protected const CUSTOMER_PAGE = '&skip_system_notification=1';

    /** Wyniki sprawdzenia w P24, czy za zamówienie już zapłacono. */
    protected const P24_UNPAID    = 'unpaid';
    protected const P24_CONFIRMED = 'confirmed';
    protected const P24_PENDING   = 'pending';
    protected const P24_MISMATCH  = 'mismatch';
    protected const P24_REFUNDED  = 'refunded';

    /**
     * Wersja zainstalowanej wtyczki, do pokazania w konfiguracji metody płatności.
     *
     * Joomla podaje wersję tylko na liście rozszerzeń, a HikaShop nie
     * podaje jej nigdzie. Przy wyłączonym serwerze aktualizacji sprzedawca
     * nie miał jak sprawdzić, co ma zainstalowane.
     *
     * Wersję bierzemy z kodu, bo to on naprawdę działa. Manifest dokłada
     * datę wydania i pozwala zauważyć instalację przerwaną w połowie, po
     * której pliki są z jednej wersji, a manifest z innej.
     *
     * @param  string|null  $manifestPath  inny manifest niż zainstalowany, na potrzeby testów
     *
     * @return array{wersja: string, data: string, manifest: string}
     */
    public static function installedVersion($manifestPath = null)
    {
        $manifest = @simplexml_load_file($manifestPath ?? JPATH_PLUGINS . '/hikashoppayment/przelewy24/przelewy24.xml');

        return [
            'wersja'   => self::VERSION,
            'data'     => $manifest !== false ? trim((string) $manifest->creationDate) : '',
            'manifest' => $manifest !== false ? trim((string) $manifest->version) : '',
        ];
    }

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

        if ($this->p24_blik_pending) {
            $this->p24_blik_status_url = $this->buildBlikStatusUrl($order);
            $this->p24_blik_check_url  = $this->buildReturnUrl($order);
        }

        return $this->showPage('end');
    }

    /**
     * Rejestruje transakcję w P24 i przygotowuje adres strony płatności.
     *
     * Wspólne dla złożenia zamówienia i ponowienia zapłaty. Przy błędzie
     * ustawia komunikat dla klienta w p24_error i zwraca null. Null zwraca
     * też wtedy, gdy za zamówienie już zapłacono: informacja dla klienta
     * trafia wówczas do p24_paid_notice.
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

            // Stan zamówienia czytamy z bazy, nie z obiektu, który dostaliśmy:
            // kasa podaje zamówienie bez danych transakcji, a o tym, czy już
            // zapłacono, decyduje to, co zapisane.
            $zapisane = $this->getOrder((int) $order->order_id);
            $stan     = \is_object($zapisane) ? $zapisane : $order;

            // Zamówienie opłacone nie może być opłacone po raz drugi.
            // Bez tej blokady klient, który wróci do kasy, zakłada w P24
            // kolejną transakcję i płaci drugi raz za to samo.
            if ($this->isAlreadyPaid($stan, $config)) {
                $logger->warning('Próba ponownej zapłaty za opłacone zamówienie', [
                    'order_id' => $order->order_id,
                    'status'   => $stan->order_status ?? '',
                ]);

                $this->p24_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ALREADY_PAID');
                $this->p24_retry_url   = '';

                return null;
            }

            $client  = $this->buildClient($config, $logger);
            $service = new TransactionService($client, $config, $logger);

            // Powiadomienie o zapłacie potrafi nie dotrzeć: awaria, zapora,
            // sklep bez adresu widocznego z internetu. Klient widzi wtedy
            // zamówienie jako nieopłacone i chce zapłacić jeszcze raz. Zanim
            // wyślemy go do bramki, pytamy P24 o transakcję tego zamówienia.
            // Opłaconą potwierdzamy od razu, tak samo jak po powiadomieniu.
            try {
                $wP24 = $this->settleIfPaidInP24($stan, $config, $logger, $service, $amount, $currencyCode);
            } catch (ApiException $exception) {
                // Skoro nie wiemy, czy poprzednia próba nie została opłacona,
                // nowej transakcji nie zakładamy. Klient może spróbować za chwilę.
                $logger->error('Nie udało się sprawdzić w P24, czy za zamówienie już zapłacono', [
                    'order_id' => $order->order_id ?? 0,
                    'http'     => $exception->getHttpStatus(),
                    'powod'    => $exception->getMessage(),
                ]);

                $this->p24_error = $exception->isAuthenticationFailure()
                    ? Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_AUTH')
                    : Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_REGISTER');

                return null;
            }

            if ($wP24 !== self::P24_UNPAID) {
                $this->p24_retry_url = '';

                match ($wP24) {
                    self::P24_CONFIRMED => $this->p24_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_CONFIRMED'),
                    self::P24_PENDING   => $this->p24_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_PENDING'),
                    self::P24_MISMATCH  => $this->p24_error = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_MISMATCH'),
                    default             => $this->p24_error = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_UNAVAILABLE'),
                };

                return null;
            }

            // P24 odrzuca adresy e-mail dłuższe niż 50 znaków. Przycięty
            // adres należałby już do kogoś innego, więc płatności nie
            // zaczynamy i mówimy klientowi wprost, w czym rzecz. Ponowienie
            // niczego by nie zmieniło, stąd brak przycisku.
            $email = $this->customerEmail($order);

            if (!RegisterRequest::isEmailAccepted($email)) {
                $logger->error('Adres e-mail klienta jest dłuższy, niż przyjmuje P24', [
                    'order_id' => $order->order_id,
                    'znakow'   => mb_strlen($email),
                    'limit'    => RegisterRequest::EMAIL_MAX_LENGTH,
                ]);

                $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_ERROR_EMAIL_TOO_LONG');
                $this->p24_retry_url = '';

                return null;
            }

            // Każda próba zapłaty dostaje własną sesję, czyli nową transakcję
            // w P24. Tej samej nie da się dokończyć: po nieudanej płatności
            // jej strona od razu odsyła klienta z powrotem do sklepu.
            // Przed zapłaceniem dwa razy chroni pytanie zadane wyżej, które
            // obejmuje wszystkie wcześniejsze sesje zamówienia.
            $sessionId = SessionId::generate((int) $order->order_id);

            // Zapisujemy próbę PRZED wysłaniem rejestracji. Gdyby P24
            // zdążyło przysłać powiadomienie, zanim wrócimy z odpowiedzią,
            // identyfikator sesji jest już przy zamówieniu.
            OrderPaymentData::startAttempt(
                (int) $order->order_id,
                $sessionId,
                $amount,
                $currencyCode
            );

            $token = $service->register(new RegisterRequest(
                sessionId: $sessionId,
                amountInMinorUnits: $amount,
                currency: $currencyCode,
                description: Text::sprintf(
                    'PLG_HIKASHOPPAYMENT_PRZELEWY24_ORDER_DESCRIPTION',
                    $order->order_number
                ),
                email: $email,
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
                method: $config->paymentMethodId > 0 ? $config->paymentMethodId : null,
                // Dane płatnika potrzebne tylko do BLIK-a w sklepie
                clientIp: $config->blikInShop ? $this->clientIp() : '',
                clientUserAgent: $config->blikInShop ? $this->clientUserAgent() : '',
                urlBlikNotification: $config->blikInShop ? $this->buildBlikNotifyUrl($order) : ''
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
        $input = Factory::getApplication()->getInput();

        // Ten sam punkt wejścia obsługuje przycisk ponowienia zapłaty.
        // HikaShop w wersji Starter nie ma własnego „zapłać teraz”,
        // a zadanie notify jest dostępne w każdej wersji.
        $akcja = $input->getCmd('p24_action', '');

        if ($akcja === 'retry') {
            return $this->handleRetry();
        }

        // Tędy wraca też klient ze strony płatności P24. P24 odsyła go na
        // ten sam adres po zapłacie, po błędzie i po rezygnacji, więc zanim
        // podziękujemy za zamówienie, pytamy P24, jak było naprawdę.
        if ($akcja === 'return') {
            return $this->handleReturn();
        }

        // Strona oczekiwania na potwierdzenie BLIK pyta tędy o wynik.
        if ($akcja === 'blik_status') {
            return $this->handleBlikStatus();
        }

        // Dodatkowe powiadomienie BLIK: wynik autoryzacji w banku.
        if ($akcja === 'blik_notify') {
            return $this->handleBlikNotification();
        }

        $orderId  = (int) $input->get('order_id', 0, 'int');
        $urlToken = (string) $input->get('order_token', '', 'string');

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

            // Każda próba zapłaty ma własną sesję, a wpłata może dotyczyć
            // wcześniejszej: przelew tradycyjny dochodzi po godzinach, klient
            // mógł też zapłacić z odnośnika w wiadomości od P24. Przyjmujemy
            // więc dowolną sesję zapisaną przy TYM zamówieniu i żadnej innej.
            // Dla nieznanej sesji do porównania idzie bieżąca i powiadomienie
            // odpada na pierwszym sprawdzeniu.
            $storedSessionId = \in_array($notification->sessionId, OrderPaymentData::sessionsOf($dbOrder), true)
                ? $notification->sessionId
                : OrderPaymentData::getString($dbOrder, OrderPaymentData::SESSION_ID);
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
            //
            // Miarą jest nasz własny znacznik weryfikacji, a nie status
            // zamówienia. Status sprzedawca potrafi zmienić ręcznie i sam
            // z siebie nie mówi, czy P24 dostało od nas transaction/verify.
            // Bez weryfikacji P24 nie rozlicza wpłaty, więc pominięcie jej
            // przy ręcznie potwierdzonym zamówieniu kosztowałoby pieniądze.
            if (OrderPaymentData::getString($dbOrder, OrderPaymentData::VERIFIED_AT) !== '') {
                $znanaTransakcja = OrderPaymentData::getInt($dbOrder, OrderPaymentData::P24_ORDER_ID);

                if ($znanaTransakcja > 0 && $znanaTransakcja !== $notification->p24OrderId) {
                    // Druga, osobna wpłata za to samo zamówienie. Nie
                    // weryfikujemy jej: niezweryfikowana zostaje w P24
                    // do dyspozycji klienta, czyli do niego wraca.
                    $logger->warning('Druga transakcja P24 dla zweryfikowanego już zamówienia, nie weryfikuję jej', [
                        'order_id'          => $orderId,
                        'p24_order'         => $notification->p24OrderId,
                        'zweryfikowana_p24' => $znanaTransakcja,
                    ]);
                } else {
                    $logger->info('Powtórzone powiadomienie pominięte, zapłata jest już zweryfikowana', [
                        'order_id' => $orderId,
                        'status'   => $dbOrder->order_status,
                    ]);
                }

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
                // P24 odpowiedziało poprawnie, ale statusem innym niż
                // „success”. To jedyna jawna odmowa, jaką znamy, i tylko
                // ona nadaje zamówieniu status nieudanej płatności.
                $logger->error('Weryfikacja nie potwierdziła zapłaty', ['order_id' => $orderId]);
                $this->markInvalid($orderId, $config, $logger);

                return false;
            }

            return $this->markVerified($orderId, $config, $logger, [
                OrderPaymentData::PAID_SESSION => $storedSessionId,
            ]);
        } catch (SignatureException $exception) {
            // Niezgodność podpisu, sesji, kwoty lub waluty. Zamówienia
            // nie wolno wtedy ruszyć.
            $logger->error('Powiadomienie odrzucone', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return false;
        } catch (ApiException $exception) {
            $logger->error('Weryfikacja transakcji nie powiodła się', [
                'order_id' => $orderId,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            // Statusu zamówienia tu nie ruszamy, niezależnie od kodu błędu.
            // P24 wysyła powiadomienia wyłącznie dla transakcji opłaconych,
            // więc błąd weryfikacji po poprawnie podpisanym powiadomieniu
            // oznacza kłopot po naszej stronie albo po stronie P24:
            // nieaktualny klucz, adres IP spoza listy w panelu, awarię,
            // przekroczony czas. Klient zapłacił. Status nieudanej płatności
            // odwołałby mu zamówienie i zwrócił towar na stan.
            //
            // P24 ponawia powiadomienie przez kilka godzin, a sprzedawca
            // dostaje jedną wiadomość, żeby zdążył usunąć przyczynę.
            $this->alertMerchant($orderId, $dbOrder, $exception, $logger);

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

        // Strona „Zapłać teraz” HikaShopa nie wyświetla własnego HTML-a
        // metody płatności ani nie odsyła znacznika payment_custom_html,
        // na który czeka jej kontroler. Z polem BLIK klient krążył tam
        // w kółko między wyborem metody a pustą stroną i nigdy nie
        // docierał do bramki. Poza kasą pola więc nie ma: klient idzie
        // prosto na stronę płatności P24, gdzie BLIK i tak jest.
        if ($this->isPayLaterRequest()) {
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
            . '<img class="hikashop_przelewy24_blik_logo" src="'
            . $this->escape(Uri::root(true) . '/media/plg_hikashoppayment_przelewy24/BLIK.svg')
            . '" alt="BLIK" width="48" height="24" style="vertical-align:middle;margin-right:8px" />'
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
        $code = BlikService::normaliseCode((string) $app->getInput()->getString('hikashop_przelewy24_blik_code', ''));

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

        // Na stronie „Zapłać teraz” pola kodu nie ma, więc kod w sesji
        // może pochodzić tylko z porzuconej kasy i jest dawno nieważny.
        if ($this->isPayLaterRequest()) {
            return '';
        }

        return BlikService::normaliseCode($code);
    }

    /**
     * Czy klient płaci za istniejące zamówienie przez „Zapłać teraz”
     * HikaShopa (order&task=pay), a nie składa nowe w kasie.
     */
    protected function isPayLaterRequest()
    {
        $input = Factory::getApplication()->getInput();

        // HikaShop przyjmuje też parę view/layout zamiast ctrl/task.
        $ctrl = $input->getCmd('ctrl', '') ?: $input->getCmd('view', '');
        $task = $input->getCmd('task', '') ?: $input->getCmd('layout', '');

        return $ctrl === 'order' && $task === 'pay';
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
     * Wołane wyłącznie wtedy, gdy P24 wprost odpowiedziało, że transakcja
     * nie jest potwierdzona. Błąd HTTP, zerwane połączenie czy odrzucone
     * dane dostępowe odmową nie są: patrz alertMerchant().
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
     * Zawiadamia sprzedawcę, że zapłaty nie udało się zweryfikować.
     *
     * Jedna wiadomość na zamówienie: P24 ponawia powiadomienie kilka razy
     * i bez tej blokady każde powtórzenie dokładałoby kolejny e-mail.
     * Wiadomość idzie na adres powiadomień o płatnościach z konfiguracji
     * HikaShopa, tą samą drogą, której używają wtyczki rdzenia.
     */
    protected function alertMerchant($orderId, $order, ApiException $exception, Logger $logger)
    {
        if (\is_object($order) && OrderPaymentData::getString($order, OrderPaymentData::VERIFY_ALERT_AT) !== '') {
            return;
        }

        try {
            $adresat = \function_exists('hikashop_config')
                ? trim((string) hikashop_config()->get('payment_notification_email', ''))
                : '';

            if ($adresat === '') {
                $logger->warning('Brak adresu powiadomień o płatnościach w konfiguracji HikaShopa, sprzedawca nie dostanie wiadomości', [
                    'order_id' => $orderId,
                ]);

                return;
            }

            $numer     = (string) ($order->order_number ?? $orderId);
            $odpowiedz = $exception->getHttpStatus() > 0
                ? 'HTTP ' . $exception->getHttpStatus() . ', ' . $exception->getMessage()
                : $exception->getMessage();

            $email          = new \stdClass();
            $email->subject = Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFY_ALERT_SUBJECT', $numer);
            $email->body    = implode("\n\n", [
                Text::sprintf('PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFY_ALERT_INTRO', $numer, $logger->redact($odpowiedz)),
                Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFY_ALERT_STATE'),
                Text::sprintf(
                    'PLG_HIKASHOPPAYMENT_PRZELEWY24_VERIFY_ALERT_CAUSES',
                    OrderPaymentData::getString(\is_object($order) ? $order : null, OrderPaymentData::SESSION_ID)
                ),
            ]);

            // Fałsz w miejscu numeru zamówienia każe klasie bazowej wysłać
            // samą wiadomość, bez zapisywania czegokolwiek w zamówieniu.
            $bezZamowienia = false;
            $this->modifyOrder($bezZamowienia, null, null, $email);

            OrderPaymentData::store((int) $orderId, [
                OrderPaymentData::VERIFY_ALERT_AT => gmdate('c'),
            ]);

            $logger->warning('Sprzedawca zawiadomiony o nieudanej weryfikacji', ['order_id' => $orderId]);
        } catch (Throwable $blad) {
            $logger->error('Nie udało się zawiadomić sprzedawcy o nieudanej weryfikacji', [
                'order_id' => $orderId,
                'powod'    => $blad->getMessage(),
            ]);
        }
    }

    /**
     * Zapisuje potwierdzoną zapłatę i nadaje zamówieniu status opłaconego.
     *
     * Wspólne dla obu dróg, którymi dowiadujemy się o zapłacie: powiadomienia
     * z P24 i własnego pytania do P24, gdy powiadomienie nie dotarło. Wołać
     * wyłącznie po udanym transaction/verify.
     *
     * @param  array<string, mixed>  $dane  dodatkowe dane transakcji do zapisania
     *
     * @return bool  czy zamówienie jest w statusie opłaconego
     */
    protected function markVerified($orderId, Config $config, Logger $logger, array $dane = [])
    {
        $orderId = (int) $orderId;

        // Powiadomienie i klient wracający z bramki potrafią trafić do sklepu
        // w tej samej chwili. Kto przyszedł drugi, ten niczego nie powtarza:
        // ani zmiany statusu, ani e-maila do klienta.
        $przed = $this->getOrder($orderId);

        if (\is_object($przed) && OrderPaymentData::getString($przed, OrderPaymentData::VERIFIED_AT) !== '') {
            $logger->info('Zapłata została już potwierdzona przez równoległe żądanie', ['order_id' => $orderId]);

            return $this->hasPaidStatus($przed, $config);
        }

        OrderPaymentData::store($orderId, $dane + [
            OrderPaymentData::VERIFIED_AT => gmdate('c'),
        ]);

        // Zamówienie mogło dostać status opłaconego wcześniej, na
        // przykład ręcznie od sprzedawcy, albo pójść już dalej, do
        // wysyłki. Zapłatę trzeba było zweryfikować tak czy inaczej,
        // ale statusu nie cofamy i klienta drugi raz nie zawiadamiamy.
        $aktualne = $this->getOrder($orderId);

        if ($this->hasPaidStatus($aktualne, $config)) {
            $logger->info('Zapłata zweryfikowana, status zamówienia zostaje bez zmian', [
                'order_id' => $orderId,
                'status'   => (string) ($aktualne->order_status ?? ''),
            ]);

            return true;
        }

        // Zmiana statusu wysyła też powiadomienie do klienta. Gdyby
        // wysyłka się wywróciła, zapłata i tak jest zaksięgowana.
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
        if (!$this->hasPaidStatus($this->getOrder($orderId), $config)) {
            $logger->error('Zapłata potwierdzona, ale status zamówienia się nie zmienił', [
                'order_id'          => $orderId,
                'oczekiwany_status' => $config->verifiedStatus,
            ]);

            return false;
        }

        $logger->info('Zamówienie oznaczone jako opłacone', [
            'order_id' => $orderId,
            'status'   => $config->verifiedStatus,
        ]);

        return true;
    }

    /**
     * Pyta P24, czy za zamówienie już zapłacono, i opłacone potwierdza.
     *
     * Powiadomienie o zapłacie potrafi nie dotrzeć. Sklep widzi wtedy
     * zamówienie jako nieopłacone i pokazuje klientowi „Zapłać teraz”, choć
     * pieniądze są już w P24. Kliknięcie tego przycisku nie może prowadzić
     * do bramki drugi raz: najpierw pytamy P24 o transakcję zamówienia,
     * a opłaconą weryfikujemy dokładnie tak, jak po powiadomieniu.
     *
     * To nie jest uznawanie zapłaty na słowo przeglądarki. O stan pytamy
     * P24 z serwera, a status zmienia dopiero udane transaction/verify.
     *
     * @param  object  $order           zamówienie odczytane z bazy
     * @param  int     $expectedAmount  kwota zamówienia w groszach
     *
     * @return string  jedna ze stałych P24_*
     */
    protected function settleIfPaidInP24($order, Config $config, Logger $logger, TransactionService $service, $expectedAmount, $currencyCode)
    {
        $sesje = \is_object($order) ? OrderPaymentData::sessionsOf($order) : [];
        $wynik = self::P24_UNPAID;

        // Bez zapisanej sesji nie było jeszcze żadnej próby zapłaty.
        // Każda próba ma własną sesję, więc wpłata mogła trafić do dowolnej
        // z nich: pytamy o wszystkie, od najnowszej.
        foreach ($sesje as $sessionId) {
            $stanSesji = $this->settleSession($order, $sessionId, $config, $logger, $service, $expectedAmount, $currencyCode);

            // Wpłata w kwocie zamówienia rozstrzyga sprawę od razu.
            if ($stanSesji === self::P24_CONFIRMED || $stanSesji === self::P24_PENDING) {
                return $stanSesji;
            }

            // Wpłata w złej kwocie jest ważniejsza niż zwrot: pieniądze
            // klienta nadal leżą w P24 i nie wolno brać od niego kolejnych.
            if ($stanSesji === self::P24_MISMATCH
                || ($stanSesji === self::P24_REFUNDED && $wynik === self::P24_UNPAID)
            ) {
                $wynik = $stanSesji;
            }
        }

        return $wynik;
    }

    /**
     * Sprawdza w P24 jedną sesję zamówienia i opłaconą potwierdza.
     *
     * @return string  jedna ze stałych P24_*
     *
     * @throws ApiException  gdy P24 nie odpowiada na pytanie o stan sesji
     */
    protected function settleSession($order, string $sessionId, Config $config, Logger $logger, TransactionService $service, $expectedAmount, $currencyCode)
    {
        $orderId    = (int) ($order->order_id ?? 0);
        $transakcja = $service->findBySessionId($sessionId);

        if ($transakcja === null) {
            return self::P24_UNPAID;
        }

        $stan = (int) ($transakcja['status'] ?? 0);

        if ($stan === TransactionService::STATE_REFUNDED) {
            $logger->warning('Wpłata za to zamówienie została w P24 zwrócona, nowej płatności nie zaczynam', [
                'order_id' => $orderId,
            ]);

            return self::P24_REFUNDED;
        }

        if ($stan !== TransactionService::STATE_ADVANCE && $stan !== TransactionService::STATE_PAID) {
            return self::P24_UNPAID;
        }

        $p24OrderId = (int) ($transakcja['orderId'] ?? 0);
        $kwota      = (int) ($transakcja['amount'] ?? 0);
        $waluta     = (string) ($transakcja['currency'] ?? '');

        if ($p24OrderId <= 0
            || !Amount::equals((int) $expectedAmount, $kwota)
            || strcasecmp($waluta, (string) $currencyCode) !== 0
        ) {
            $logger->error('W P24 jest wpłata za to zamówienie, ale w innej kwocie lub walucie niż zamówienie', [
                'order_id'      => $orderId,
                'p24_order'     => $p24OrderId,
                'wplata_gr'     => $kwota,
                'zamowienie_gr' => (int) $expectedAmount,
                'waluta_p24'    => $waluta,
            ]);

            return self::P24_MISMATCH;
        }

        $logger->info('P24 ma już wpłatę za to zamówienie, weryfikuję ją bez czekania na powiadomienie', [
            'order_id'  => $orderId,
            'p24_order' => $p24OrderId,
            'stan_p24'  => $stan,
        ]);

        try {
            $verified = $service->verify($sessionId, $p24OrderId, (int) $expectedAmount, (string) $currencyCode);
        } catch (ApiException $exception) {
            $logger->error('Weryfikacja wpłaty znalezionej w P24 nie powiodła się', [
                'order_id' => $orderId,
                'http'     => $exception->getHttpStatus(),
                'powod'    => $exception->getMessage(),
            ]);

            // Klient zapłacił, więc do bramki go nie wysyłamy. Sprzedawca
            // dostaje wiadomość, tak samo jak przy nieudanej weryfikacji
            // po powiadomieniu.
            $this->alertMerchant($orderId, $order, $exception, $logger);

            return self::P24_PENDING;
        }

        if (!$verified) {
            $logger->error('P24 nie potwierdziło wpłaty znalezionej dla zamówienia', ['order_id' => $orderId]);

            return self::P24_PENDING;
        }

        $this->markVerified($orderId, $config, $logger, [
            OrderPaymentData::P24_ORDER_ID => $p24OrderId,
            OrderPaymentData::METHOD_ID    => (int) ($transakcja['paymentMethod'] ?? 0),
            OrderPaymentData::PAID_SESSION => $sessionId,
        ]);

        return self::P24_CONFIRMED;
    }

    /**
     * Czy za zamówienie już zapłacono.
     *
     * Najpewniejszym dowodem jest nasz własny znacznik weryfikacji.
     * Status zamówienia jest dowodem słabszym, ale jedynym dla zamówień
     * potwierdzonych ręcznie przez sprzedawcę.
     */
    protected function isAlreadyPaid($order, Config $config)
    {
        if (\is_object($order) && OrderPaymentData::getString($order, OrderPaymentData::VERIFIED_AT) !== '') {
            return true;
        }

        return $this->hasPaidStatus($order, $config);
    }

    /**
     * Czy zamówienie jest w statusie oznaczającym zapłatę.
     *
     * Sam status potwierdzenia z konfiguracji metody nie wystarcza:
     * zamówienie wysłane też jest opłacone, a cofnięcie go do statusu
     * potwierdzenia wysłałoby klientowi drugi e-mail. Listę statusów
     * opłaconych HikaShop trzyma u siebie, jako statusy, w których
     * wystawia fakturę.
     */
    protected function hasPaidStatus($order, Config $config)
    {
        $current = (string) ($order->order_status ?? '');

        if ($current === '') {
            return false;
        }

        $paid = [$config->verifiedStatus];

        if (\function_exists('hikashop_config')) {
            $lista = (string) hikashop_config()->get('invoice_order_statuses', 'confirmed,shipped');
            $paid  = array_merge($paid, array_map('trim', explode(',', $lista)));
        }

        return \in_array($current, $paid, true);
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
     * Adres, pod który P24 odsyła klienta ze strony płatności.
     *
     * P24 używa go po zapłacie, po błędzie i po rezygnacji, bez żadnej
     * informacji o wyniku. Dlatego nie prowadzi wprost do podziękowania,
     * tylko do wtyczki, która pyta P24 o stan płatności: patrz handleReturn().
     */
    protected function buildReturnUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&p24_action=return'
            . self::CUSTOMER_PAGE
            . '&order_id=' . (int) $order->order_id
            . '&p24_return=' . $this->returnToken($order)
            . $this->customerUrlSuffix();
    }

    /**
     * Adres, na który P24 przysyła wynik autoryzacji BLIK w banku.
     *
     * Ten sam znacznik co przy zwykłym powiadomieniu: oba adresy zna
     * tylko P24 i oba prowadzą do sprawdzenia podpisu.
     */
    protected function buildBlikNotifyUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&p24_action=blik_notify'
            . '&tmpl=component'
            . '&lang=' . urlencode((string) ($this->locale ?? ''))
            . '&order_id=' . (int) $order->order_id
            . '&order_token=' . md5((string) $order->order_token);
    }

    /**
     * Adres, pod którym strona oczekiwania pyta o wynik płatności BLIK.
     *
     * Bez dopisku CUSTOMER_PAGE i celowo: odpowiedzią jest JSON dla skryptu,
     * a wtyczka systemowa HikaShopa oddaje wynik zadania notify bez szablonu
     * witryny. Adres jest względny, żeby pytanie zawsze szło do tej samej
     * domeny, z której klient ogląda stronę.
     */
    protected function buildBlikStatusUrl($order)
    {
        return Uri::root(true) . '/index.php?option=com_hikashop&ctrl=checkout&task=notify'
            . '&notif_payment=' . $this->name
            . '&p24_action=blik_status'
            . '&tmpl=component&format=raw'
            . '&lang=' . urlencode((string) ($this->locale ?? ''))
            . '&order_id=' . (int) $order->order_id
            . '&p24_status=' . $this->statusToken($order);
    }

    /**
     * Znacznik adresu pytania o wynik płatności BLIK, osobny od pozostałych.
     */
    protected function statusToken($order)
    {
        return hash_hmac('sha256', 'p24-status|' . (int) $order->order_id, (string) ($order->order_token ?? ''));
    }

    /**
     * Dopisek adresów stron, które ogląda klient: znacznik zamówienia
     * gościa i pozycja menu.
     *
     * HikaShop podaje tu pozycję menu, z której klient przyszedł. Przy
     * zakupie to kasa, ale „Zapłać teraz” z e-maila albo z listy zamówień
     * niesie pozycję menu sklepu lub konta. Podziękowanie i komunikaty
     * o płatności wyświetlały się wtedy w cudzym układzie, na przykład
     * z boczną kolumną sklepu. Gdy w konfiguracji HikaShopa jest wskazana
     * pozycja menu kasy, strony płatności zawsze jej używają.
     */
    protected function customerUrlSuffix()
    {
        $suffix = (string) ($this->url_itemid ?? '');
        $kasa   = \function_exists('hikashop_config') ? (int) hikashop_config()->get('checkout_itemid', 0) : 0;

        if ($kasa <= 0) {
            return $suffix;
        }

        return preg_replace('/&Itemid=\d*/', '', $suffix) . '&Itemid=' . $kasa;
    }

    /**
     * Znacznik adresu powrotu, osobny od znacznika ponowienia i powiadomień.
     */
    protected function returnToken($order)
    {
        return hash_hmac('sha256', 'p24-return|' . (int) $order->order_id, (string) ($order->order_token ?? ''));
    }

    /**
     * Adres strony podziękowania HikaShopa.
     *
     * Zapłaty nie potwierdza. Klient trafia tu dopiero wtedy, gdy zapłata
     * jest potwierdzona w P24.
     */
    protected function buildThanksUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=after_end'
            . '&order_id=' . (int) $order->order_id
            . $this->customerUrlSuffix();
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
            . self::CUSTOMER_PAGE
            . '&order_id=' . (int) $order->order_id
            . '&p24_retry=' . $this->retryToken($order)
            . $this->customerUrlSuffix();
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
     * Zanim założy nową transakcję, pyta P24, czy któraś z wcześniejszych
     * prób nie została opłacona. Po udanej rejestracji przekierowuje
     * klienta prosto na stronę płatności.
     */
    protected function handleRetry()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('p24_retry', '', 'string');

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

        if (\in_array($status, $this->closedStatuses(), true)) {
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
     * Przyjmuje klienta wracającego ze strony płatności P24.
     *
     * P24 odsyła klienta na ten sam adres bez względu na wynik, a po
     * nieudanej płatności nie pokazuje mu nawet komunikatu. Gdyby adres
     * powrotu prowadził wprost do podziękowania, nieudana płatność
     * wyglądałaby w sklepie jak udana. Pytamy więc P24 o stan płatności:
     *
     * - wpłata jest: weryfikujemy ją tak samo jak po powiadomieniu i dopiero
     *   wtedy pokazujemy podziękowanie
     * - wpłaty nie ma: klient czyta, że płatność nie została potwierdzona,
     *   i dostaje przycisk ponowienia
     * - P24 nie odpowiada: nie zgadujemy, klient dostaje neutralną informację
     *
     * To nadal nie jest uznawanie zapłaty na słowo przeglądarki. Stan
     * podaje P24 w odpowiedzi na pytanie z serwera, a status zamówienia
     * zmienia wyłącznie udane transaction/verify.
     */
    protected function handleReturn()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('p24_return', '', 'string');

        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadPaymentParams($dbOrder)
            || !hash_equals($this->returnToken($dbOrder), $token)
        ) {
            $this->writeToLog('P24 [BŁĄD] Powrót z bramki z błędnym znacznikiem | order_id=' . $orderId);

            $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_INVALID');
            $this->p24_retry_url = '';

            return $this->renderPage('end');
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        $this->loadOrderData($dbOrder);

        // Powiadomienie z P24 zwykle zdąża przed klientem.
        if ($this->isAlreadyPaid($dbOrder, $config)) {
            $this->redirectTo($this->buildThanksUrl($dbOrder));

            return true;
        }

        $zamkniete = \in_array((string) ($dbOrder->order_status ?? ''), $this->closedStatuses(), true);

        $this->p24_retry_url = $zamkniete ? '' : $this->buildRetryUrl($dbOrder);

        try {
            $config->assertComplete();

            $wP24 = $this->settleIfPaidInP24(
                $dbOrder,
                $config,
                $logger,
                $this->buildService($config, $logger),
                Amount::toMinorUnit($dbOrder->order_full_price, $this->currencyFractionDigits()),
                $this->currencyCode()
            );
        } catch (Throwable $exception) {
            $logger->error('Nie udało się sprawdzić stanu płatności po powrocie klienta z bramki', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            $this->p24_unpaid_notice = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETURN_UNKNOWN');

            return $this->renderPage('end');
        }

        if ($wP24 === self::P24_CONFIRMED) {
            $this->redirectTo($this->buildThanksUrl($dbOrder));

            return true;
        }

        $logger->info('Klient wrócił z bramki bez potwierdzonej zapłaty', [
            'order_id' => $orderId,
            'stan'     => $wP24,
        ]);

        if ($wP24 === self::P24_PENDING) {
            $this->p24_paid_notice = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_PENDING');
            $this->p24_retry_url   = '';
        } elseif ($wP24 === self::P24_MISMATCH) {
            $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_PAID_MISMATCH');
            $this->p24_retry_url = '';
        } elseif ($wP24 === self::P24_REFUNDED) {
            $this->p24_error     = Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETRY_UNAVAILABLE');
            $this->p24_retry_url = '';
        } else {
            // Po kodzie BLIK wpisanym w kasie bank potrafi powiedzieć, czemu
            // odmówił. Klient czyta wtedy konkret, a nie ogólnik.
            $odrzucenie = $this->blikRejection($dbOrder);

            $this->p24_unpaid_notice = $odrzucenie !== null
                ? Text::_($odrzucenie->languageKey())
                : Text::_('PLG_HIKASHOPPAYMENT_PRZELEWY24_RETURN_UNPAID');
        }

        return $this->renderPage('end');
    }

    /**
     * Odpowiada stronie oczekiwania, jak skończyła się płatność kodem BLIK.
     *
     * Po przyjęciu kodu klient zostaje w sklepie i potwierdza płatność
     * w aplikacji banku. Do wersji 1.0.8 strona oczekiwania stała w miejscu:
     * klient potwierdzał, zamówienie się opłacało, a on dalej czytał
     * „Potwierdź płatność w aplikacji banku” (serwer testowy, 09.10.2026).
     *
     * Odpowiedź to JSON z polem state:
     *
     * - paid: zapłata potwierdzona, w redirect adres podziękowania
     * - failed: bank odrzucił płatność, w redirect strona z powodem i ponowieniem
     * - check: sprawa wymaga strony powrotu (wpłata bez weryfikacji, zła kwota,
     *   zamówienie zamknięte), w redirect jej adres
     * - waiting: jeszcze nic nie wiadomo
     * - invalid: błędny znacznik albo nieznane zamówienie
     *
     * Zwykle zapłatę potwierdza powiadomienie z P24 i wtedy wystarcza odczyt
     * zamówienia z bazy. P24 pytamy tylko na prośbę skryptu (p24_ask), który
     * robi to rzadziej niż samo odpytywanie. To nadal nie jest uznawanie
     * zapłaty na słowo przeglądarki: stan podaje P24 w odpowiedzi na pytanie
     * z serwera, a status zamówienia zmienia wyłącznie udane transaction/verify.
     */
    protected function handleBlikStatus()
    {
        $input   = Factory::getApplication()->getInput();
        $orderId = (int) $input->get('order_id', 0, 'int');
        $token   = (string) $input->get('p24_status', '', 'string');

        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder)
            || !$this->loadPaymentParams($dbOrder)
            || !hash_equals($this->statusToken($dbOrder), $token)
        ) {
            return $this->jsonReply(['state' => 'invalid']);
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        $this->loadOrderData($dbOrder);

        if ($this->isAlreadyPaid($dbOrder, $config)) {
            return $this->jsonReply(['state' => 'paid', 'redirect' => $this->buildThanksUrl($dbOrder)]);
        }

        if ($this->blikRejection($dbOrder) !== null) {
            return $this->jsonReply(['state' => 'failed', 'redirect' => $this->buildReturnUrl($dbOrder)]);
        }

        if (\in_array((string) ($dbOrder->order_status ?? ''), $this->closedStatuses(), true)) {
            return $this->jsonReply(['state' => 'check', 'redirect' => $this->buildReturnUrl($dbOrder)]);
        }

        $sessionId = OrderPaymentData::getString($dbOrder, OrderPaymentData::SESSION_ID);

        if ($sessionId === '' || $input->getInt('p24_ask', 0) !== 1) {
            return $this->jsonReply(['state' => 'waiting']);
        }

        try {
            $config->assertComplete();

            // Tylko bieżąca sesja, czyli ta obciążona kodem. Wcześniejsze
            // sprawdzi strona powrotu, gdy klient na nią trafi.
            $wP24 = $this->settleSession(
                $dbOrder,
                $sessionId,
                $config,
                $logger,
                $this->buildService($config, $logger),
                Amount::toMinorUnit($dbOrder->order_full_price, $this->currencyFractionDigits()),
                $this->currencyCode()
            );
        } catch (Throwable $exception) {
            // Chwilowy brak odpowiedzi P24 niczego nie rozstrzyga: skrypt
            // zapyta jeszcze raz, a w ostateczności klient trafi na stronę
            // powrotu, która ma na to własny komunikat.
            $logger->error('Nie udało się sprawdzić w P24 wyniku płatności BLIK', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
            ]);

            return $this->jsonReply(['state' => 'waiting']);
        }

        if ($wP24 === self::P24_CONFIRMED) {
            return $this->jsonReply(['state' => 'paid', 'redirect' => $this->buildThanksUrl($dbOrder)]);
        }

        if ($wP24 === self::P24_UNPAID) {
            return $this->jsonReply(['state' => 'waiting']);
        }

        return $this->jsonReply(['state' => 'check', 'redirect' => $this->buildReturnUrl($dbOrder)]);
    }

    /**
     * Przyjmuje dodatkowe powiadomienie BLIK z wynikiem autoryzacji w banku.
     *
     * Zapisuje przy zamówieniu przyczynę odrzucenia, żeby strona oczekiwania
     * mogła od razu powiedzieć klientowi, co się stało. Statusu zamówienia
     * nie zmienia: zamówienie zostaje do opłacenia, a klient może ponowić.
     * Powodzenia też nie przyjmuje za zapłatę, od tego jest powiadomienie
     * na urlStatus i transaction/verify.
     */
    protected function handleBlikNotification()
    {
        $input    = Factory::getApplication()->getInput();
        $orderId  = (int) $input->get('order_id', 0, 'int');
        $urlToken = (string) $input->get('order_token', '', 'string');

        $dbOrder = $this->getOrder($orderId);

        if (empty($dbOrder) || !$this->loadPaymentParams($dbOrder)) {
            $this->writeToLog('P24 [BŁĄD] Powiadomienie BLIK dla nieznanego zamówienia | order_id=' . $orderId);

            return false;
        }

        $config = Config::fromPaymentParams($this->payment_params);
        $logger = $this->buildLogger($config);

        if (!hash_equals(md5((string) $dbOrder->order_token), $urlToken)) {
            $logger->error('Powiadomienie BLIK z błędnym znacznikiem zamówienia', ['order_id' => $orderId]);

            return false;
        }

        $body = $this->readNotificationBody();

        try {
            $powiadomienie = BlikNotification::fromRequestBody($body);
            $powiadomienie->assertValid($config, OrderPaymentData::sessionsOf($dbOrder));
        } catch (SignatureException $exception) {
            $logger->error('Powiadomienie BLIK odrzucone', [
                'order_id' => $orderId,
                'powod'    => $exception->getMessage(),
                'tresc'    => BlikNotification::describeBody($body),
            ]);

            return false;
        }

        $logger->info('Powiadomienie BLIK przyjęte', ['order_id' => $orderId] + $powiadomienie->toLogContext());

        $zapisanaSesja = OrderPaymentData::getString($dbOrder, OrderPaymentData::BLIK_ERROR_SESSION);
        $zapisanyBlad  = OrderPaymentData::getString($dbOrder, OrderPaymentData::BLIK_ERROR);

        if (!$powiadomienie->hasError()) {
            // Bank przyjął płatność. Wcześniejsze odrzucenie tej samej
            // sesji przestaje obowiązywać.
            if ($zapisanyBlad !== '' && $zapisanaSesja === $powiadomienie->sessionId) {
                OrderPaymentData::store($orderId, [
                    OrderPaymentData::BLIK_ERROR         => '',
                    OrderPaymentData::BLIK_ERROR_SESSION => '',
                ]);
            }

            return 'OK';
        }

        $przyczyna = $powiadomienie->reason();

        // P24 potrafi powtórzyć powiadomienie. Zapis tylko przy zmianie,
        // bo każdy dokłada wpis w historii zamówienia.
        if ($zapisanyBlad !== $przyczyna->value || $zapisanaSesja !== $powiadomienie->sessionId) {
            OrderPaymentData::store($orderId, [
                OrderPaymentData::BLIK_ERROR         => $przyczyna->value,
                OrderPaymentData::BLIK_ERROR_SESSION => $powiadomienie->sessionId,
            ]);
        }

        $logger->warning('Bank odrzucił płatność BLIK', [
            'order_id' => $orderId,
            'powod'    => $przyczyna->value,
        ]);

        return 'OK';
    }

    /**
     * Przyczyna, dla której bank odrzucił bieżącą próbę zapłaty kodem BLIK.
     *
     * Liczy się tylko odrzucenie bieżącej sesji. Po ponowieniu zamówienie
     * ma nową sesję i stara przyczyna nie może straszyć klienta.
     */
    protected function blikRejection($order): ?BlikError
    {
        if (!\is_object($order)) {
            return null;
        }

        $blad  = OrderPaymentData::getString($order, OrderPaymentData::BLIK_ERROR);
        $sesja = OrderPaymentData::getString($order, OrderPaymentData::BLIK_ERROR_SESSION);

        if ($blad === '' || $sesja === '' || $sesja !== OrderPaymentData::getString($order, OrderPaymentData::SESSION_ID)) {
            return null;
        }

        return BlikError::tryFrom($blad);
    }

    /**
     * Odpowiedź w JSON-ie dla skryptu strony oczekiwania.
     */
    protected function jsonReply(array $dane)
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        return (string) json_encode($dane, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Statusy zamówień zamkniętych: anulowanych i zwróconych.
     *
     * Do dwóch nazw rdzenia dokładamy listę z konfiguracji HikaShopa,
     * bo sklep może mieć własne statusy anulowania.
     *
     * @return list<string>
     */
    protected function closedStatuses()
    {
        $statusy = ['cancelled', 'refunded'];

        if (\function_exists('hikashop_config')) {
            $lista   = (string) hikashop_config()->get('cancelled_order_status', 'cancelled,refunded');
            $statusy = array_merge($statusy, array_map('trim', explode(',', $lista)));
        }

        return array_values(array_unique(array_filter($statusy, static fn ($status): bool => $status !== '')));
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
        $email = self::emailFrom($order->customer->user_email ?? '');

        if ($email === '' && !empty($order->order_user_id)) {
            $customer = hikashop_get('class.user')->get((int) $order->order_user_id);
            $email    = self::emailFrom($customer->user_email ?? '');
        }

        if ($email === '') {
            $email = self::emailFrom($this->user->user_email ?? '');
        }

        return $email;
    }

    /**
     * Wyciąga adres z pola user_email.
     *
     * Przy zakupie gościa HikaShop przekazuje w zamówieniu user_email jako
     * tablicę z jednym adresem. Rzutowanie na tekst dawało "Array", a P24
     * odrzucało rejestrację błędem "Invalid email".
     */
    private static function emailFrom($value): string
    {
        if (\is_array($value)) {
            $value = reset($value);
        }

        if (!\is_string($value)) {
            return '';
        }

        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : '';
    }

    /**
     * Adres IP klienta z żądania (dla additional.PSU przy BLIK-u).
     *
     * Bierzemy REMOTE_ADDR, a nie nagłówki X-Forwarded-For: te ustawia
     * klient i nie wolno im wierzyć bez znajomości serwera pośredniczącego.
     */
    protected function clientIp(): string
    {
        return (string) Factory::getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
    }

    protected function clientUserAgent(): string
    {
        return (string) Factory::getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');
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
     *
     * Kwotę bierzemy z bazy, czyli z tego samego miejsca, z którego
     * weźmie ją potem obsługa powiadomienia. Koszyk trzyma ją jako liczbę
     * zmiennoprzecinkową o pełnej precyzji, a baza zaokrągla do pięciu
     * miejsc. Przy wartości tuż pod granicą pół grosza (koszyk 10,0049999
     * daje 1000 gr, baza 10,00500 daje 1001 gr) obie drogi dawały różne
     * grosze i powiadomienie o prawidłowej zapłacie byłoby odrzucane
     * jako niezgodne z kwotą zamówienia.
     */
    protected function orderTotal($order)
    {
        $orderId = (int) ($order->order_id ?? 0);

        if ($orderId > 0) {
            $zapisane = $this->getOrder($orderId);

            if (\is_object($zapisane) && isset($zapisane->order_full_price) && (float) $zapisane->order_full_price > 0) {
                return $zapisane->order_full_price;
            }
        }

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
