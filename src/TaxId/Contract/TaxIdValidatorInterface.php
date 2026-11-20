<?php

declare(strict_types=1);

namespace TaxId\Contract;

use TaxId\Result\ValidationResult;

/**
 * Contract for country-specific tax identifier validators.
 *
 * Validators perform local validation only. A valid result means that the
 * identifier satisfies the implemented format/control rules; it does not
 * prove that the identifier is assigned, active or present in an official
 * registry.
 */
interface TaxIdValidatorInterface
{
    /**
     * Returns the ISO 3166-1 alpha-2 country code handled by the validator.
     */
    public function countryCode(): string;

    /**
     * Validates a tax identifier using the rules for the validator's country.
     */
    public function validate(string $taxId): ValidationResult;
}
