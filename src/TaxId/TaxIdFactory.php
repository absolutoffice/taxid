<?php

declare(strict_types=1);

namespace TaxId;

use InvalidArgumentException;
use TaxId\Config\EnvLoader;
use TaxId\Country\AD\AndorranTaxIdValidator;
use TaxId\Country\ES\SpanishTaxIdValidator;
use TaxId\Country\PT\NifPtProvider;
use TaxId\Country\PT\PortugueseTaxIdValidator;

/**
 * Creates the default TaxId service and optional external providers.
 */
final class TaxIdFactory
{
    private const DEFAULT_NIF_PT_BASE_URL = 'https://www.nif.pt/';
    private const DEFAULT_NIF_PT_TIMEOUT = 5;

    /**
     * Create a service with local ES, PT and AD validators.
     *
     * The external nif.pt provider is registered only if a non-empty API key
     * is supplied; without a key, local validation still works.
     *
     * @param array{
     *     nif_pt?: array{
     *         api_key?: string|null,
     *         base_url?: string|null,
     *         timeout?: int|string|null
     *     }
     * } $config Optional nif.pt configuration.
     * @return TaxIdService Configured validation and lookup service.
     * @throws InvalidArgumentException If nif.pt configuration is invalid.
     */
    public static function create(array $config = []): TaxIdService
    {
        $registry = new TaxIdRegistry([
            new SpanishTaxIdValidator(),
            new PortugueseTaxIdValidator(),
            new AndorranTaxIdValidator(),
        ]);

        $nifPt = $config['nif_pt'] ?? [];
        $apiKey = trim((string) ($nifPt['api_key'] ?? ''));

        if ($apiKey !== '') {
            $baseUrl = trim((string) ($nifPt['base_url'] ?? self::DEFAULT_NIF_PT_BASE_URL));
            if ($baseUrl === '') {
                $baseUrl = self::DEFAULT_NIF_PT_BASE_URL;
            }

            $timeout = self::normalizeTimeout($nifPt['timeout'] ?? self::DEFAULT_NIF_PT_TIMEOUT);

            $registry->registerProvider(new NifPtProvider(
                apiKey: $apiKey,
                baseUrl: $baseUrl,
                timeoutSeconds: $timeout,
            ));
        }

        return new TaxIdService($registry);
    }

    /**
     * Create the service from process variables and an optional .env file.
     *
     * Values from getenv(), $_ENV and $_SERVER take precedence over the file.
     * Passing null uses process values only. A missing file is ignored; an
     * existing but unreadable or malformed file triggers an exception.
     *
     * @param string|null $envFile Full path to a .env file, or null to skip it.
     * @return TaxIdService Configured validation and lookup service.
     * @throws \RuntimeException If an existing .env file cannot be read or parsed.
     * @throws InvalidArgumentException If nif.pt configuration is invalid.
     */
    public static function fromEnvironment(?string $envFile = null): TaxIdService
    {
        $fileValues = $envFile !== null ? EnvLoader::load($envFile) : [];

        return self::create([
            'nif_pt' => [
                'api_key' => self::environmentValue('NIF_PT_API_KEY', $fileValues),
                'base_url' => self::environmentValue('NIF_PT_BASE_URL', $fileValues)
                    ?? self::DEFAULT_NIF_PT_BASE_URL,
                'timeout' => self::environmentValue('NIF_PT_TIMEOUT', $fileValues)
                    ?? self::DEFAULT_NIF_PT_TIMEOUT,
            ],
        ]);
    }

    /**
     * Resolve one environment setting in precedence order.
     *
     * Checks getenv(), then $_ENV, then $_SERVER, finally values from the
     * optional .env file; non-scalar entries in the arrays are ignored.
     *
     * @param string $key Environment variable name.
     * @param array<string, string> $fileValues Parsed values from an optional file.
     * @return string|null Resolved value or null when absent.
     */
    private static function environmentValue(string $key, array $fileValues): ?string
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        $envValue = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if (is_scalar($envValue)) {
            return (string) $envValue;
        }

        return $fileValues[$key] ?? null;
    }

    /**
     * Validate and normalize a nif.pt timeout value.
     *
     * @param int|string|null $value Configured timeout in seconds.
     * @return int Positive timeout value.
     * @throws InvalidArgumentException If it is not a positive integer.
     */
    private static function normalizeTimeout(int|string|null $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException('NIF_PT_TIMEOUT must be an integer.');
        }

        $timeout = (int) $value;
        if ($timeout < 1) {
            throw new InvalidArgumentException('NIF_PT_TIMEOUT must be at least 1 second.');
        }

        return $timeout;
    }
}
