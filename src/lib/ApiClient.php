<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

use Joomla\CMS\Http\HttpFactory;
use Joomla\Http\Http;
use Joomla\Http\Response;
use Joomla\Registry\Registry;
use WebService\Przelewy24\Exception\ApiException;

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
     * @throws ApiException
     */
    public function get(string $endpoint): ApiResponse
    {
        return $this->request('GET', $endpoint, null);
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
     *
     * @throws ApiException
     */
    private function request(string $method, string $endpoint, ?array $payload): ApiResponse
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
            $this->logger->error('P24 odrzuciło żądanie', [
                'endpoint' => $endpoint,
                'opis'     => $apiResponse->describe(),
            ]);

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
            $this->http = HttpFactory::getHttp(new Registry(['timeout' => self::TIMEOUT_SECONDS]));
        }

        return $this->http;
    }
}
