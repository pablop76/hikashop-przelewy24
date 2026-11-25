<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Joomla\CMS\Factory;
use Joomla\CMS\Version;
use Joomla\Http\Http;
use Joomla\Http\HttpFactory;
use Joomla\Http\Response;
use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ApiException;

\defined('_JEXEC') or die;

/**
 * Klient REST API P24.
 *
 * Korzysta z klienta HTTP Joomli zamiast gołego cURL-a, żeby wtyczka
 * podlegała ustawieniom serwera proxy i limitom czasu witryny.
 *
 * Każda droga wyjścia z tej klasy to albo ApiResponse o kodzie 200,
 * albo wyjątek. Nie ma ścieżki, w której wywołujący dostaje ciszę
 * i musi sam zgadywać, czy żądanie doszło.
 */
final class ApiClient
{
    /**
     * Ten sam limit, co w oficjalnej wtyczce P24 dla WooCommerce.
     * Bramki płatnicze potrafią odpowiadać wolno przy obciążeniu.
     */
    private const TIMEOUT_SECONDS = 45;

    private ?Http $http = null;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $pluginVersion = '1.0.0',
        private readonly string $siteUrl = '',
        ?Http $http = null
    ) {
        $this->http = $http;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ApiException
     */
    public function post(string $endpoint, array $payload): ApiResponse
    {
        return $this->request('POST', $endpoint, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ApiException
     */
    public function put(string $endpoint, array $payload): ApiResponse
    {
        return $this->request('PUT', $endpoint, $payload);
    }

    /**
     * @param  list<int>  $expectedStatuses  kody HTTP, które przy tym pytaniu są zwykłą
     *                                       odpowiedzią, a nie awarią; nadal kończą się
     *                                       wyjątkiem, ale nie zaśmiecają dziennika błędami
     *
     * @throws ApiException
     */
    public function get(string $endpoint, array $expectedStatuses = []): ApiResponse
    {
        return $this->request('GET', $endpoint, null, $expectedStatuses);
    }

    /**
     * Adres, na który przekierowujemy klienta po rejestracji transakcji.
     */
    public function paywallUrl(string $token): string
    {
        return $this->config->environment->baseUrl()
            . Endpoints::build(Endpoints::PAYWALL, ['token' => $token]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  list<int>                  $expectedStatuses
     *
     * @throws ApiException
     */
    private function request(string $method, string $endpoint, ?array $payload, array $expectedStatuses = []): ApiResponse
    {
        $this->config->assertComplete();

        $url = $this->config->environment->baseUrl() . $endpoint;

        $this->logger->info('Żądanie do P24', [
            'metoda'     => $method,
            'endpoint'   => $endpoint,
            'srodowisko' => $this->config->environment->value,
        ]);

        try {
            $response = $this->send($method, $url, $payload);
        } catch (\RuntimeException $exception) {
            // Brak odpowiedzi to nie jest powodzenie transakcji.
            $this->logger->error('Brak połączenia z P24', [
                'endpoint' => $endpoint,
                'powod'    => $exception->getMessage(),
            ]);

            throw new ApiException(
                'Nie udało się połączyć z P24: ' . $exception->getMessage(),
                0,
                null,
                $exception
            );
        }

        $status = $response->getStatusCode();
        $body   = (string) $response->getBody();

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->error('Odpowiedź P24 nie jest poprawnym JSON-em', [
                'endpoint' => $endpoint,
                'http'     => $status,
                'dlugosc'  => \strlen($body),
            ]);

            throw new ApiException(
                'Odpowiedź P24 nie jest poprawnym JSON-em (HTTP ' . $status . ')',
                $status,
                null,
                $exception
            );
        }

        if (!\is_array($decoded)) {
            throw new ApiException('Nieoczekiwany kształt odpowiedzi P24 (HTTP ' . $status . ')', $status);
        }

        $apiResponse = ApiResponse::fromDecoded($status, $decoded);

        if (!$apiResponse->isSuccessful()) {
            $kontekst = [
                'endpoint' => $endpoint,
                'opis'     => $apiResponse->describe(),
            ];

            if (\in_array($status, $expectedStatuses, true)) {
                $this->logger->info('P24 odpowiedziało odmownie, czego się spodziewaliśmy', $kontekst);
            } else {
                $this->logger->error('P24 odrzuciło żądanie', $kontekst);
            }

            throw new ApiException(
                $apiResponse->errorMessage ?? ('P24 zwróciło HTTP ' . $status),
                $status,
                $apiResponse->errorCode
            );
        }

        $this->logger->info('Odpowiedź P24 przyjęta', [
            'endpoint' => $endpoint,
            'http'     => $status,
        ]);

        return $apiResponse;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function send(string $method, string $url, ?array $payload): Response
    {
        $http    = $this->getHttp();
        $headers = $this->headers();

        if ($method === 'GET') {
            return $http->get($url, $headers, self::TIMEOUT_SECONDS);
        }

        $headers['Content-Type'] = 'application/json';

        $body = json_encode(
            $payload ?? [],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        return match ($method) {
            'POST'  => $http->post($url, $body, $headers, self::TIMEOUT_SECONDS),
            'PUT'   => $http->put($url, $body, $headers, self::TIMEOUT_SECONDS),
            default => throw new ApiException('Nieobsługiwana metoda HTTP: ' . $method),
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization'          => $this->config->basicAuthHeader(),
            'Accept'                 => 'application/json',
            'P24-PLUGIN-NAME'        => 'HikaShop',
            'P24-PLUGIN-VERSION'     => $this->pluginVersion,
            'P24-PLUGIN-MERCHANT-ID' => (string) $this->config->merchantId,
            'P24-PLUGIN-WEBPAGE'     => $this->siteUrl,
        ];
    }

    private function getHttp(): Http
    {
        if ($this->http === null) {
            $this->http = (new HttpFactory())->getHttp(self::httpOptions());
        }

        return $this->http;
    }

    /**
     * Opcje klienta HTTP, razem z serwerem proxy z konfiguracji witryny.
     *
     * Klient HTTP z CMS Joomli, oznaczony do usunięcia w Joomli 8, sam
     * dokładał proxy witryny i nagłówek User-Agent Joomli. Klient z frameworka
     * tego nie robi, więc dokładamy oba tutaj, proxy jako opcje cURL, tak jak
     * robił to transport CMS.
     *
     * shortcut: proxy działa tylko z transportem cURL; gdyby hosting nie miał
     * cURL-a, a witryna wymagała proxy, trzeba dołożyć opcje dla strumieni.
     *
     * @return array<string, mixed>
     */
    public static function httpOptions(): array
    {
        $opcje = ['timeout' => self::TIMEOUT_SECONDS];

        if (!class_exists(Factory::class)) {
            return $opcje;
        }

        $opcje['userAgent'] = (new Version())->getUserAgent('Joomla', true, false);

        try {
            $app = Factory::getApplication();
        } catch (\Throwable) {
            return $opcje;
        }

        // Bez rozszerzenia cURL nie ma ani tego transportu, ani jego stałych.
        if (!$app->get('proxy_enable') || !\defined('CURLOPT_PROXY')) {
            return $opcje;
        }

        $curl = [CURLOPT_PROXY => $app->get('proxy_host') . ':' . $app->get('proxy_port')];

        if ($user = $app->get('proxy_user')) {
            $curl[CURLOPT_PROXYUSERPWD] = $user . ':' . $app->get('proxy_pass');
        }

        return $opcje + ['transport.curl' => $curl];
    }
}
