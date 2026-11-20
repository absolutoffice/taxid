<?php

declare(strict_types=1);

namespace TaxId\Country\AD;

use TaxId\Contract\TaxIdValidatorInterface;
use TaxId\Result\ValidationResult;
use TaxId\Result\ValidationStatus;

/**
 * Local format validator for Andorran NRT identifiers.
 *
 * No authoritative public checksum algorithm is encoded. A valid result means
 * the identifier matches the documented NRT format only.
 */
final class AndorranTaxIdValidator implements TaxIdValidatorInterface
{
    private const COUNTRY_CODE = 'AD';

    private const TYPE = 'NRT';

    /** {@inheritDoc} */
    public function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }

    /**
     * Check the documented Andorran NRT format and normalize separators.
     *
     * A VALID result confirms the accepted format, not assignment, official
     * registration or an authoritative checksum.
     *
     * @param string $taxId NRT supplied by the caller.
     * @return ValidationResult Format-check outcome and normalized NRT.
     */
    public function validate(string $taxId): ValidationResult
    {
        $taxId = self::normalize($taxId);

        if ($taxId === '') {
            return $this->invalid($taxId, message: 'Tax ID cannot be empty.');
        }

        // Official Andorran sources show NRTs both with an initial category
        // letter (for example D-059888-N) and, for some public entities, as
        // six digits plus a final letter (for example 800649P).
        //
        // No authoritative public checksum algorithm or exhaustive prefix
        // table is encoded here. This validator therefore verifies the
        // documented local format only; it does not prove assignment or
        // active status before the Andorran tax administration.
        if (preg_match('/^(?:[A-Z])?[0-9]{6}[A-Z]$/', $taxId) !== 1) {
            return $this->invalid(
                $taxId,
                type: self::TYPE,
                message: 'Andorran NRT must contain an optional initial letter, exactly 6 digits, and a final letter.',
            );
        }

        return new ValidationResult(
            status: ValidationStatus::VALID,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            type: self::TYPE,
        );
    }

    /**
     * Uppercase an NRT and remove whitespace, dots and hyphens.
     *
     * @param string $taxId Raw identifier.
     * @return string Normalized identifier.
     */
    private static function normalize(string $taxId): string
    {
        $taxId = strtoupper(trim($taxId));
        $normalized = preg_replace('/[\s.\-]+/u', '', $taxId);

        return $normalized ?? $taxId;
    }

    /**
     * Build an invalid NRT result without additional validation.
     *
     * @param string $taxId Already normalized identifier.
     * @param string|null $type Identifier type when determined.
     * @param string|null $message Explanation for the invalid result.
     * @return ValidationResult Result with INVALID status.
     */
    private function invalid(
        string $taxId,
        ?string $type = null,
        ?string $message = null,
    ): ValidationResult {
        return new ValidationResult(
            status: ValidationStatus::INVALID,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            type: $type,
            message: $message,
        );
    }
}
