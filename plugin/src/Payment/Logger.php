<?php
/**
 * @package     plg_hikashoppayment_przelewy24
 * @copyright   (C) 2026 Paweł Półtoraczyk
 * @license     GNU General Public License version 3 or later
 */

namespace Pablop76\Plugin\HikashopPayment\Przelewy24\Payment;

\defined('_JEXEC') or die;

/**
 * Zapis do logu HikaShopa z usuwaniem danych wrażliwych.
 *
 * Błędy i ostrzeżenia zapisujemy zawsze, bo bez nich nie da się ustalić,
 * czemu płatność nie doszła do skutku. Szczegóły techniczne tylko przy
 * włączonym trybie diagnostycznym.
 */
final class Logger
{
    private const REDACTED = '***';

    /**
     * Klucze, których wartości nie zapisujemy nigdy, niezależnie od trybu.
     */
    private const SECRET_KEYS = [
        'crc', 'crc_key', 'apikey', 'api_key', 'sign', 'signature',
        'authorization', 'password', 'haslo', 'cvv', 'cvc',
        'number', 'card_number', 'expirationdate', 'token',
    ];

    /** @var list<string> */
    private array $secrets;

    /** @var callable|null */
    private $writer;

    /**
     * @param  string        $channel  nazwa wtyczki, pod którą HikaShop trzyma log
     * @param  bool          $debug    czy zapisywać wpisy diagnostyczne
     * @param  list<string>  $secrets  wartości do wymazania z każdego wpisu
     * @param  callable|null $writer   zapis do podmiany w testach
     */
    public function __construct(
        private readonly string $channel,
        private readonly bool $debug,
        array $secrets = [],
        ?callable $writer = null
    ) {
        // Krótkie ciągi pomijamy: wymazywanie dwuznakowej wartości
        // pocięłoby cały komunikat na strzępy.
        $this->secrets = array_values(array_filter(
            $secrets,
            static fn ($secret): bool => \is_string($secret) && \strlen($secret) >= 6
        ));

        $this->writer = $writer;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('BŁĄD', $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('UWAGA', $message, $context);
    }

    /**
     * Przebieg płatności: zapisywany tylko w trybie diagnostycznym.
     *
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        if ($this->debug) {
            $this->write('INFO', $message, $context);
        }
    }

    /**
     * Usuwa z tekstu wartości sekretów.
     */
    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret, self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $line = 'P24 [' . $level . '] ' . $message;

        if ($context !== []) {
            $line .= ' | ' . $this->formatContext($context);
        }

        $line = $this->redact($line);

        if ($this->writer !== null) {
            ($this->writer)($line);

            return;
        }

        if (\function_exists('hikashop_writeToLog')) {
            hikashop_writeToLog($line, $this->channel);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatContext(array $context): string
    {
        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . $this->formatValue((string) $key, $value);
        }

        return implode(' ', $parts);
    }

    private function formatValue(string $key, mixed $value): string
    {
        if (\in_array(strtolower($key), self::SECRET_KEYS, true)) {
            return self::REDACTED;
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'tak' : 'nie';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '?';
    }
}
