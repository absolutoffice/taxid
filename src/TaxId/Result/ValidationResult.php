<?php

declare(strict_types=1);

namespace TaxId\Result;

/**
 * Immutable result of a local tax identifier validation.
 */
final class ValidationResult
{
    /**
     * Create an immutable local validation outcome.
     *
     * @param ValidationStatus $status Local validation outcome.
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @param string $taxId Identifier as returned by the validator or service.
     * @param string|null $type Country-specific identifier category, if known.
     * @param string|null $message Diagnostic text, when applicable.
     */
    public function __construct(
        public readonly ValidationStatus $status,
        public readonly string $countryCode,
        public readonly string $taxId,
        public readonly ?string $type = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * Determine whether the local validator accepted the identifier.
     *
     * @return bool True only for a VALID result.
     */
    public function isValid(): bool
    {
        return $this->status === ValidationStatus::VALID;
    }

    /**
     * Determine whether the local validator rejected the identifier.
     *
     * @return bool True only for an INVALID result.
     */
    public function isInvalid(): bool
    {
        return $this->status === ValidationStatus::INVALID;
    }

    /**
     * Determine whether a local validator supports the requested country.
     *
     * This can be true even when the identifier itself is invalid.
     *
     * @return bool False only for a NOT_SUPPORTED result.
     */
    public function isSupported(): bool
    {
        return $this->status !== ValidationStatus::NOT_SUPPORTED;
    }
}
