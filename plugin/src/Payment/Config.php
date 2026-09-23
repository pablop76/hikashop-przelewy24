<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

use Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception\ConfigurationException;

\defined('_JEXEC') or die;

/**
 * Dane dostępowe i ustawienia metody płatności.
 *
 * Obiekt jest niezmienny: powstaje raz z parametrów metody płatności
 * HikaShopa i dalej tylko go odczytujemy.
 */
final class Config
{
    public function __construct(
        public readonly int $merchantId,
        public readonly int $posId,
        public readonly string $crc,
        public readonly string $apiKey,
        public readonly Environment $environment,
        public readonly bool $debug,
        public readonly string $verifiedStatus,
        public readonly string $invalidStatus,
        public readonly string $pendingStatus
    ) {
    }

    /**
     * Buduje konfigurację z parametrów metody płatności HikaShopa.
     *
     * @param  object|null  $params  payment_params metody płatności
     */
    public static function fromPaymentParams(?object $params): self
    {
        $merchantId = (int) self::read($params, 'merchant_id', 0);
        $posId      = (int) self::read($params, 'pos_id', 0);

        return new self(
            merchantId: $merchantId,
            // P24 zwykle nadaje posId równy merchantId; puste pole nie może
            // oznaczać zera, bo takie żądanie P24 odrzuci bez czytelnej przyczyny.
            posId: $posId > 0 ? $posId : $merchantId,
            crc: trim((string) self::read($params, 'crc_key', '')),
            apiKey: trim((string) self::read($params, 'api_key', '')),
            environment: Environment::fromConfigValue(self::read($params, 'test_mode', '1')),
            debug: (string) self::read($params, 'debug', '0') === '1',
            verifiedStatus: (string) self::read($params, 'verified_status', 'confirmed'),
            invalidStatus: (string) self::read($params, 'invalid_status', 'cancelled'),
            pendingStatus: (string) self::read($params, 'pending_status', 'created')
        );
    }

    /**
     * Czy komplet danych pozwala w ogóle odezwać się do P24.
     */
    public function isComplete(): bool
    {
        return $this->merchantId > 0
            && $this->posId > 0
            && $this->crc !== ''
            && $this->apiKey !== '';
    }

    /**
     * @throws ConfigurationException
     */
    public function assertComplete(): void
    {
        if ($this->isComplete()) {
            return;
        }

        $missing = [];

        if ($this->merchantId <= 0) {
            $missing[] = 'merchant_id';
        }

        if ($this->posId <= 0) {
            $missing[] = 'pos_id';
        }

        if ($this->crc === '') {
            $missing[] = 'crc_key';
        }

        if ($this->apiKey === '') {
            $missing[] = 'api_key';
        }

        // Nazwy brakujących pól, nigdy ich wartości.
        throw new ConfigurationException('Brak danych dostępowych P24: ' . implode(', ', $missing));
    }

    /**
     * Nagłówek uwierzytelniania Basic.
     *
     * Loginem jest identyfikator sprzedawcy, hasłem klucz API (klucz do raportów).
     */
    public function basicAuthHeader(): string
    {
        return 'Basic ' . base64_encode($this->merchantId . ':' . $this->apiKey);
    }

    /**
     * Wartości, których nigdy nie wolno zapisać do logu.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->crc, $this->apiKey]));
    }

    private static function read(?object $params, string $key, mixed $default): mixed
    {
        if ($params === null || !isset($params->$key)) {
            return $default;
        }

        $value = $params->$key;

        return ($value === '' || $value === null) ? $default : $value;
    }
}
