<?php

declare(strict_types=1);

namespace TaxId\Country\PT;

use TaxId\Contract\TaxIdValidatorInterface;
use TaxId\Result\ValidationResult;
use TaxId\Result\ValidationStatus;

/**
 * Local validator for Portuguese NIF identifiers.
 *
 * Validation checks the nine-digit format, prefix constraint and control
 * digit. It does not query an external registry.
 */
final class PortugueseTaxIdValidator implements TaxIdValidatorInterface
{
    private const COUNTRY_CODE = 'PT';

    private const TYPE = 'NIF';

    /** @var list<int> */
    private const CONTROL_WEIGHTS = [9, 8, 7, 6, 5, 4, 3, 2];

    /** {@inheritDoc} */
    public function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }

    /**
     * Normalize a Portuguese NIF and verify its format and control digit.
     *
     * A VALID result confirms the implemented local checks only and does not
     * verify registration or the existence of an associated entity.
     *
     * @param string $taxId NIF supplied by the caller.
     * @return ValidationResult Validation outcome and normalized NIF.
     */
    public function validate(string $taxId): ValidationResult
    {
        $taxId = self::normalize($taxId);

        if ($taxId === '') {
            return $this->invalid($taxId, message: 'Tax ID cannot be empty.');
        }

        if (preg_match('/^[0-9]{9}$/', $taxId) !== 1) {
            return $this->invalid(
                $taxId,
                message: 'Portuguese NIF must contain exactly 9 digits.',
            );
        }

        // Portuguese NIF ranges do not use 0 as the first digit. Keeping this
        // check separate from the checksum also rejects values such as 000000000.
        if ($taxId[0] === '0') {
            return $this->invalid(
                $taxId,
                type: self::TYPE,
                message: 'Invalid Portuguese NIF prefix.',
            );
        }

        $expectedControl = self::calculateControlDigit(substr($taxId, 0, 8));
        $actualControl = (int) $taxId[8];

        if ($actualControl !== $expectedControl) {
            return $this->invalid(
                $taxId,
                type: self::TYPE,
                message: 'Invalid Portuguese NIF control digit.',
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
     * Calculate the ninth digit of a Portuguese NIF.
     *
     * Applies the standard weighted modulus-11 computation to its first eight
     * digits.
     *
     * @param string $digits First eight NIF digits.
     * @return int Calculated control digit, from 0 to 9.
     */
    private static function calculateControlDigit(string $digits): int
    {
        $sum = 0;

        foreach (self::CONTROL_WEIGHTS as $index => $weight) {
            $sum += ((int) $digits[$index]) * $weight;
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? 0 : 11 - $remainder;
    }

    /**
     * Remove whitespace, dots and hyphens from a NIF.
     *
     * @param string $taxId Raw identifier.
     * @return string Normalized identifier.
     */
    private static function normalize(string $taxId): string
    {
        $taxId = trim($taxId);
        $normalized = preg_replace('/[\s.\-]+/u', '', $taxId);

        return $normalized ?? $taxId;
    }

    /**
     * Build an invalid Portuguese NIF result.
     *
     * @param string $taxId Normalized NIF.
     * @param string|null $type Identifier type, if recognized.
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
