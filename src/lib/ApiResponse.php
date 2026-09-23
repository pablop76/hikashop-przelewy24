<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace WebService\Przelewy24;

\defined('_JEXEC') or die;

/**
 * Odpowiedź API P24 sprowadzona do jednego kształtu.
 *
 * P24 odpowiada sukcesem jako {"data": {...}, "responseCode": 0},
 * a błędem jako {"code": 400, "error": "..."}. Obie postacie trafiają tutaj,
 * żeby wywołujący nie musiał za każdym razem zgadywać struktury.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data  zawartość klucza data
     * @param  array<string, mixed>  $raw   cała zdekodowana odpowiedź
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly array $data,
        public readonly ?string $errorCode,
        public readonly ?string $errorMessage,
        public readonly array $raw
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->httpStatus === 200 && $this->errorMessage === null;
    }

    /**
     * Pole z sekcji data.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /**
     * Krótki opis do logu. Nie zawiera treści żądania, więc jest bezpieczny.
     */
    public function describe(): string
    {
        if ($this->isSuccessful()) {
            return 'HTTP ' . $this->httpStatus;
        }

        return 'HTTP ' . $this->httpStatus
            . ($this->errorCode !== null ? ', kod ' . $this->errorCode : '')
            . ($this->errorMessage !== null ? ', ' . $this->errorMessage : '');
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromDecoded(int $httpStatus, array $decoded): self
    {
        $data = $decoded['data'] ?? [];

        if (\is_object($data)) {
            $data = (array) $data;
        }

        if (!\is_array($data)) {
            // Część endpointów zwraca w data wartość prostą; opakowujemy
            // ją, żeby dalsza obsługa miała zawsze tablicę.
            $data = ['value' => $data];
        }

        $errorMessage = null;

        if (isset($decoded['error']) && $decoded['error'] !== '') {
            $errorMessage = \is_scalar($decoded['error'])
                ? (string) $decoded['error']
                : json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);
        }

        $errorCode = isset($decoded['code']) ? (string) $decoded['code'] : null;

        return new self($httpStatus, $data, $errorCode, $errorMessage, $decoded);
    }
}
