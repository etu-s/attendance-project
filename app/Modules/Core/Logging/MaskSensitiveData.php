<?php

declare(strict_types=1);

namespace App\Modules\Core\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Masks personal data and secrets before a log record is written
 * (CLAUDE.md: "Mask sensitive personal data in logs").
 *
 *  - by key, anywhere in the context: passwords, tokens, keys, signatures,
 *    names, emails, phones, punch identifiers;
 *  - by pattern, in messages, string context values and exception
 *    messages/traces: email addresses, punch source keys (ams_…), bearer
 *    tokens, and long digit runs (phone numbers, national IDs).
 *
 * An exception in the context is replaced by its masked text (class,
 * message, location, trace), because its message cannot be changed in place.
 */
final class MaskSensitiveData implements ProcessorInterface
{
    public const MASK = '[masked]';

    /** Context keys whose value is always masked (compared case-insensitively, by suffix). */
    private const KEYS = [
        'password', 'password_confirmation', 'current_password', 'token', 'secret', 'api_key', 'key_hash',
        'authorization', 'cookie', 'signature', 'remember_token', 'email', 'phone', 'first_name', 'last_name',
        'full_name', 'employee_identifier', 'identifier',
    ];

    /** @var array<string, string> pattern => replacement */
    private const PATTERNS = [
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
        '/\bams_[A-Za-z0-9]+_[A-Za-z0-9]+\b/' => '[key]',
        '/\bBearer\s+[A-Za-z0-9._~+\/=\-|]+/i' => 'Bearer [token]',
        // Not dates (2026-03-01): logs need them.
        '/(?<![\w.:-])(?!\d{4}-\d{2}-\d{2})\+?\d[\d \-]{8,}\d(?![\w.:-])/' => '[number]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::maskString($record->message),
            context: $this->maskArray($record->context),
            extra: $this->maskArray($record->extra),
        );
    }

    public static function maskString(string $value): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function maskArray(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $data[$key] = self::MASK;
            } elseif ($value instanceof Throwable) {
                $data[$key] = $this->maskThrowable($value);
            } elseif (is_string($value)) {
                $data[$key] = self::maskString($value);
            } elseif (is_array($value) && $depth < 8) {
                $data[$key] = $this->maskArray($value, $depth + 1);
            }
        }

        return $data;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::KEYS as $sensitive) {
            if ($key === $sensitive || str_ends_with($key, '_'.$sensitive) || str_ends_with($key, '.'.$sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function maskThrowable(Throwable $e): string
    {
        $text = '';
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $text .= ($text === '' ? '' : "\n[previous] ")
                .$current::class.': '.$current->getMessage().' at '.$current->getFile().':'.$current->getLine()
                ."\n".$current->getTraceAsString();
        }

        return self::maskString($text);
    }
}
