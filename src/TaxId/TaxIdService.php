<?php

declare(strict_types=1);

namespace TaxId;

use TaxId\Result\LookupResult;
use TaxId\Result\LookupStatus;
use TaxId\Result\ValidationResult;
use TaxId\Result\ValidationStatus;

/**
 * Public façade for tax identifier validation and external lookup.
 *
 * The service keeps validation and lookup independent: lack of provider data
 * never changes the local validation outcome.
 */
final class TaxIdService
{
    /**
     * Create a service backed by a validator/provider registry.
     *
     * @param TaxIdRegistry $registry Registry to use for validation and lookup.
     */
    public function __construct(
        private readonly TaxIdRegistry $registry,
    ) {
    }

    /**
     * Validate a tax identifier using the country's local validator.
     *
     * When the country has no validator, returns NOT_SUPPORTED rather than
     * INVALID. Lookup availability never changes this local result.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @param string $taxId Identifier to validate.
     * @return ValidationResult Local validation result.
     * @throws \InvalidArgumentException If the country code is malformed.
     */
    public function validate(string $countryCode, string $taxId): ValidationResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $taxId = trim($taxId);

        $validator = $this->registry->validator($countryCode);

        if ($validator === null) {
            return new ValidationResult(
                status: ValidationStatus::NOT_SUPPORTED,
                countryCode: $countryCode,
                taxId: $taxId,
                message: sprintf('Tax ID validation is not supported for country %s.', $countryCode),
            );
        }

        return $validator->validate($taxId);
    }

    /**
     * Request external information associated with a tax identifier.
     *
     * When no provider is registered, returns NOT_SUPPORTED. Remote lookup
     * results do not imply local validation or registration status.
     *
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @param string $taxId Identifier to look up.
     * @return LookupResult External lookup result.
     * @throws \InvalidArgumentException If the country code is malformed.
     */
    public function lookup(string $countryCode, string $taxId): LookupResult
    {
        $countryCode = strtoupper(trim($countryCode));
        $taxId = trim($taxId);

        $provider = $this->registry->provider($countryCode);

        if ($provider === null) {
            return new LookupResult(
                status: LookupStatus::NOT_SUPPORTED,
                countryCode: $countryCode,
                taxId: $taxId,
                message: sprintf('Tax ID lookup is not supported for country %s.', $countryCode),
            );
        }

        return $provider->lookup($taxId);
    }
}
