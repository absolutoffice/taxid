<?php

declare(strict_types=1);

namespace TaxId\Config;

use RuntimeException;

/**
 * Minimal .env parser used by the standalone TaxId bootstrap.
 *
 * It intentionally supports only the subset needed by this library and does
 * not modify the process environment.
 */
final class EnvLoader
{
    /**
     * Parse a .env file without changing the process environment.
     *
     * Supports KEY=VALUE pairs, optional "export ", quotes and comments. A
     * missing file returns an empty array; an existing unreadable file or
     * malformed entry causes an exception.
     *
     * @param string $path Full path to the optional .env file.
     * @return array<string, string> Parsed environment variable names and values.
     * @throws RuntimeException If an existing file cannot be read or parsed.
     */
    public static function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Unable to read TaxId environment file.');
        }

        $values = [];

        foreach ($lines as $lineNumber => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                throw new RuntimeException(sprintf(
                    'Invalid TaxId .env entry on line %d.',
                    $lineNumber + 1,
                ));
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if (preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key) !== 1) {
                throw new RuntimeException(sprintf(
                    'Invalid TaxId .env key on line %d.',
                    $lineNumber + 1,
                ));
            }

            $values[$key] = self::parseValue($value, $lineNumber + 1);
        }

        return $values;
    }

    /**
     * Parse a value from one .env entry.
     *
     * Double quotes allow common backslash escapes; single quotes are literal.
     * Unquoted values may end in an inline comment introduced by " #".
     *
     * @param string $value Raw value after the equals sign.
     * @param int $lineNumber One-based line number used for error reporting.
     * @return string Parsed value without surrounding quotes or comments.
     * @throws RuntimeException If a quoted value is not closed.
     */
    private static function parseValue(string $value, int $lineNumber): string
    {
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        if ($first !== '"' && $first !== "'") {
            $comment = strpos($value, ' #');
            if ($comment !== false) {
                $value = substr($value, 0, $comment);
            }

            return trim($value);
        }

        if (strlen($value) < 2 || !str_ends_with($value, $first)) {
            throw new RuntimeException(sprintf(
                'Unclosed quoted value in TaxId .env on line %d.',
                $lineNumber,
            ));
        }

        $value = substr($value, 1, -1);

        if ($first === '"') {
            return str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $value,
            );
        }

        return $value;
    }
}
