<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Środowisko P24.
 *
 * Typ wyliczeniowy zamiast flagi logicznej, żeby adres bramki wynikał
 * z jednego miejsca i żeby nie dało się przypadkiem wysłać żądania
 * testowego na produkcję przez literówkę w warunku.
 */
enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';

    /**
     * Buduje środowisko na podstawie pola konfiguracji wtyczki.
     *
     * Domyślnie sandbox: brak lub nieczytelna wartość nie może
     * skutkować wysłaniem żądania na produkcję.
     */
    public static function fromConfigValue(mixed $value): self
    {
        return ((string) $value === '0') ? self::Production : self::Sandbox;
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Sandbox    => 'https://sandbox.przelewy24.pl/',
            self::Production => 'https://secure.przelewy24.pl/',
        };
    }

    public function isSandbox(): bool
    {
        return $this === self::Sandbox;
    }
}
