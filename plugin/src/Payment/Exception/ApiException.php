<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment\Exception;

\defined('_JEXEC') or die;

/**
 * Nieudane wywołanie API P24: błąd połączenia, kod HTTP inny niż 200,
 * niepoprawny JSON albo odpowiedź bez oczekiwanych pól.
 */
final class ApiException extends Przelewy24Exception
{
    /**
     * @param  string       $message     opis dla logu, bez sekretów
     * @param  int          $httpStatus  kod HTTP odpowiedzi, 0 gdy nie doszło do odpowiedzi
     * @param  string|null  $apiCode     kod błędu zwrócony przez P24, jeżeli był
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $apiCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getApiCode(): ?string
    {
        return $this->apiCode;
    }

    /**
     * Czy przyczyną jest odrzucenie danych dostępowych przez P24.
     */
    public function isAuthenticationFailure(): bool
    {
        return $this->httpStatus === 401;
    }
}
