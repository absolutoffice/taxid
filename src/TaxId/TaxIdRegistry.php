<?php

declare(strict_types=1);

namespace TaxId;

use InvalidArgumentException;
use TaxId\Contract\TaxIdProviderInterface;
use TaxId\Contract\TaxIdValidatorInterface;

/**
 * Registry of country-specific validators and external providers.
 *
 * A later registration for the same country replaces the previous entry,
 * allowing callers to override the default implementation deliberately.
 */
final class TaxIdRegistry
{
    /** @var array<string, TaxIdValidatorInterface> */
    private array $validators = [];

    /** @var array<string, TaxIdProviderInterface> */
    private array $providers = [];

    /**
     * Initialize the registry with optional validators and providers.
     *
     * Later registrations for a country override earlier ones.
     *
     * @param iterable<TaxIdValidatorInterface> $validators Initial local validators.
     * @param iterable<TaxIdProviderInterface> $providers Initial lookup providers.
     * @throws InvalidArgumentException If an implementation returns an invalid country code.
     */
    public function __construct(
        iterable $validators = [],
        iterable $providers = [],
    ) {
        foreach ($validators as $validator) {
            $this->registerValidator($validator);
        }

        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    /**
     * Register or replace the local validator for its country.
     *
     * @param TaxIdValidatorInterface $validator Validator to register.
     * @return void
     * @throws InvalidArgumentException If its country code is invalid.
     */
    public function registerValidator(TaxIdValidatorInterface $validator): void
    {
        $countryCode = self::normalizeCountryCode($validator->countryCode());
        $this->validators[$countryCode] = $validator;
    }

    /**
     * Register or replace the external provider for its country.
     *
     * @param TaxIdProviderInterface $provider Provider to register.
     * @return void
     * @throws InvalidArgumentException If its country code is invalid.
     */
    public function registerProvider(TaxIdProviderInterface $provider): void
    {
        $countryCode = self::normalizeCountryCode($provider->countryCode());
        $this->providers[$countryCode] = $provider;
    }

    /**
     * Get the local validator registered for a country, if any.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code (case-insensitive).
     * @return TaxIdValidatorInterface|null Registered validator or null.
     * @throws InvalidArgumentException If the country code is malformed.
     */
    public function validator(string $countryCode): ?TaxIdValidatorInterface
    {
        $countryCode = self::normalizeCountryCode($countryCode);

        return $this->validators[$countryCode] ?? null;
    }

    /**
     * Get the external lookup provider registered for a country, if any.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code (case-insensitive).
     * @return TaxIdProviderInterface|null Registered provider or null.
     * @throws InvalidArgumentException If the country code is malformed.
     */
    public function provider(string $countryCode): ?TaxIdProviderInterface
    {
        $countryCode = self::normalizeCountryCode($countryCode);

        return $this->providers[$countryCode] ?? null;
    }

    /**
     * Check whether a validator is registered for a country.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @return bool Whether a validator exists.
     * @throws InvalidArgumentException If the country code is malformed.
     */
    public function hasValidator(string $countryCode): bool
    {
        return $this->validator($countryCode) !== null;
    }

    /**
     * Check whether a provider is registered for a country.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @return bool Whether a provider exists.
     * @throws InvalidArgumentException If the country code is malformed.
     */
    public function hasProvider(string $countryCode): bool
    {
        return $this->provider($countryCode) !== null;
    }

    /**
     * Trim, uppercase and check a two-letter country code.
     *
     * @param string $countryCode Country identifier provided by the caller.
     * @return string Uppercase ISO alpha-2-shaped code.
     * @throws InvalidArgumentException If it is not exactly two ASCII letters.
     */
    private static function normalizeCountryCode(string $countryCode): string
    {
        $countryCode = strtoupper(trim($countryCode));

        if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid country code "%s". Expected ISO 3166-1 alpha-2.', $countryCode),
            );
        }

        return $countryCode;
    }
}
