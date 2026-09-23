<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 * @link        https://www.web-service.com.pl
 */

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use WebService\Przelewy24\Amount;
use WebService\Przelewy24\ApiClient;
use WebService\Przelewy24\Autoloader;
use WebService\Przelewy24\Config;
use WebService\Przelewy24\Exception\ApiException;
use WebService\Przelewy24\Exception\ConfigurationException;
use WebService\Przelewy24\Exception\SignatureException;
use WebService\Przelewy24\Logger;
use WebService\Przelewy24\Notification;
use WebService\Przelewy24\OrderPaymentData;
use WebService\Przelewy24\RegisterRequest;
use WebService\Przelewy24\SessionId;
use WebService\Przelewy24\TransactionService;

defined('_JEXEC') or die('Restricted access');

require_once __DIR__ . '/lib/Autoloader.php';

Autoloader::register(__DIR__ . '/lib');

/**
 * Płatności Przelewy24 dla HikaShopa.
 *
 * Nazwa klasy i położenie pliku są narzucone przez HikaShopa: funkcja
 * hikashop_import() ładuje wtyczkę przez require_once ze ścieżki
 * plugins/hikashoppayment/<nazwa>/<nazwa>.php i tworzy obiekt klasy
 * plgHikashoppayment<Nazwa>, z pominięciem kontenera Joomli.
 *
 * Metody on* muszą mieć sygnatury bez typów, zgodne z klasą bazową.
 * Cała logika P24 siedzi w src/lib, gdzie typy już są.
 */
class plgHikashoppaymentPrzelewy24 extends hikashopPaymentPlugin
{
    /**
     * Wersja wtyczki, wysyłana do P24 w nagłówku diagnostycznym.
     */
    public const VERSION = '1.0.0';

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
        'refund'            => false,
    ];

    /**
     * Token strony płatności, odczytywany przez przelewy24_end.php.
     *
     * @var string
     */
    public $p24_token = '';

    /**
     * Gotowy adres strony płatności P24, odczytywany przez widok.
     *
     * @var string
     */
    public $p24_paywall_url = '';

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
        $element->payment_params->pending_status  = 'created';
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

        try {
            $config->assertComplete();

            $currencyCode  = $this->currencyCode();
            $fractionDigit = $this->currencyFractionDigits();
            $amount        = Amount::toMinorUnit($this->orderTotal($order), $fractionDigit);

            if ($amount <= 0) {
                throw new ConfigurationException('Kwota zamówienia jest zerowa lub ujemna');
            }

            $sessionId = SessionId::generate((int) $order->order_id);

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

            $client  = new ApiClient($config, $logger, self::VERSION, HIKASHOP_LIVE);
            $service = new TransactionService($client, $config, $logger);

            $token = $service->register(new RegisterRequest(
                sessionId: $sessionId,
                amountInMinorUnits: $amount,
                currency: $currencyCode,
                description: Text::sprintf(
                    'PLG_HIKASHOPPAYMENT_PRZELEWY24_ORDER_DESCRIPTION',
                    $order->order_number
                ),
                email: (string) ($this->user->user_email ?? ''),
                urlReturn: $this->buildReturnUrl($order),
                urlStatus: $this->buildNotifyUrl($order),
                country: $this->billingCountry($order),
                language: (string) ($this->locale ?: 'pl'),
                client: $this->billingName($order),
                address: $this->billingField($order, 'address_street'),
                zip: $this->billingField($order, 'address_post_code'),
                city: $this->billingField($order, 'address_city'),
                phone: $this->billingField($order, 'address_telephone')
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

        return $this->showPage('end');
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

            $notification = Notification::fromRequestBody((string) file_get_contents('php://input'));

            $storedSessionId = OrderPaymentData::getString($dbOrder, OrderPaymentData::SESSION_ID);
            $currencyCode    = $this->currencyCode();
            $expectedAmount  = Amount::toMinorUnit(
                $dbOrder->order_full_price,
                $this->currencyFractionDigits()
            );

            $notification->assertValid($config, $storedSessionId, $expectedAmount, $currencyCode);

            $logger->info('Powiadomienie przyjęte', ['order_id' => $orderId] + $notification->toLogContext());

            OrderPaymentData::store($orderId, [
                OrderPaymentData::P24_ORDER_ID => $notification->p24OrderId,
                OrderPaymentData::METHOD_ID    => $notification->methodId,
            ]);

            // Powiadomienie potrafi przyjść więcej niż raz. Bez tego
            // sprawdzenia każde powtórzenie ruszałoby stan magazynowy
            // i wysyłało klientowi kolejny e-mail.
            if ($this->isAlreadyPaid($dbOrder, $config)) {
                $logger->info('Powtórzone powiadomienie pominięte, zamówienie już opłacone', [
                    'order_id' => $orderId,
                    'status'   => $dbOrder->order_status,
                ]);

                return true;
            }

            $service = $this->buildService($config, $logger);

            $verified = $service->verify(
                $storedSessionId,
                $notification->p24OrderId,
                $expectedAmount,
                $currencyCode
            );

            if (!$verified) {
                $logger->error('Weryfikacja nie potwierdziła zapłaty', ['order_id' => $orderId]);

                return false;
            }

            OrderPaymentData::store($orderId, [
                OrderPaymentData::VERIFIED_AT => gmdate('c'),
            ]);

            $this->modifyOrder($orderId, $config->verifiedStatus, true, true);

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

    protected function buildService(Config $config, Logger $logger)
    {
        $client = new ApiClient($config, $logger, self::VERSION, HIKASHOP_LIVE);

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
     * Powrót do kasy, gdy płatność nie ruszyła.
     */
    protected function buildRetryUrl($order)
    {
        return HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=step'
            . '&step=0' . $this->url_itemid;
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
