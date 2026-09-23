<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Dane rejestracji transakcji (transaction/register).
 *
 * Osobny obiekt zamiast tablicy, bo przy rejestracji łatwo pomylić
 * kolejność argumentów, a kwota i waluta muszą trafić tu już przeliczone.
 */
final class RegisterRequest
{
    /**
     * Języki obsługiwane przez stronę płatności P24.
     */
    private const SUPPORTED_LANGUAGES = [
        'bg', 'cs', 'de', 'en', 'es', 'fr', 'hr', 'hu',
        'it', 'nl', 'pl', 'pt', 'se', 'sk',
    ];

    /**
     * @param  int     $amountInMinorUnits  kwota w groszach
     * @param  int     $timeLimit           limit w minutach, 0 oznacza limit P24
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly int $amountInMinorUnits,
        public readonly string $currency,
        public readonly string $description,
        public readonly string $email,
        public readonly string $urlReturn,
        public readonly string $urlStatus,
        public readonly string $country = 'PL',
        public readonly string $language = 'pl',
        public readonly string $client = '',
        public readonly string $address = '',
        public readonly string $zip = '',
        public readonly string $city = '',
        public readonly string $phone = '',
        public readonly int $timeLimit = 0,
        public readonly ?int $method = null
    ) {
    }

    /**
     * Buduje treść żądania wraz z podpisem.
     *
     * @return array<string, mixed>
     */
    public function toPayload(Config $config): array
    {
        $payload = [
            'merchantId'  => $config->merchantId,
            'posId'       => $config->posId,
            'sessionId'   => $this->sessionId,
            'amount'      => $this->amountInMinorUnits,
            'currency'    => strtoupper($this->currency),
            'description' => self::trimTo($this->description, 1024),
            'email'       => self::trimTo($this->email, 50),
            'country'     => strtoupper(self::trimTo($this->country, 2)),
            'language'    => self::normaliseLanguage($this->language),
            'urlReturn'   => $this->urlReturn,
            'urlStatus'   => $this->urlStatus,
            'sign'        => Signature::forRegister(
                $this->sessionId,
                $config->merchantId,
                $this->amountInMinorUnits,
                strtoupper($this->currency),
                $config->crc
            ),
            'encoding'    => 'UTF-8',
        ];

        // Pola nieobowiązkowe dokładamy tylko wypełnione: P24 odrzuca
        // część z nich, gdy przyjdą puste.
        $optional = [
            'client'  => self::trimTo($this->client, 50),
            'address' => self::trimTo($this->address, 50),
            'zip'     => self::trimTo($this->zip, 10),
            'city'    => self::trimTo($this->city, 50),
            'phone'   => self::trimTo($this->phone, 12),
        ];

        foreach ($optional as $key => $value) {
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        if ($this->timeLimit > 0) {
            $payload['timeLimit'] = min($this->timeLimit, 99);
        }

        if ($this->method !== null && $this->method > 0) {
            $payload['method'] = $this->method;
        }

        return $payload;
    }

    /**
     * Sprowadza kod języka do postaci przyjmowanej przez P24.
     */
    private static function normaliseLanguage(string $language): string
    {
        $code = strtolower(substr($language, 0, 2));

        return \in_array($code, self::SUPPORTED_LANGUAGES, true) ? $code : 'en';
    }

    /**
     * Przycina wartość do limitu API, licząc znaki, nie bajty.
     */
    private static function trimTo(string $value, int $limit): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $limit);
    }
}
