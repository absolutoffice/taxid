<?php

declare(strict_types=1);

namespace TaxId\Country\ES;

use LogicException;
use TaxId\Contract\TaxIdValidatorInterface;
use TaxId\Result\ValidationResult;
use TaxId\Result\ValidationStatus;

/**
 * Local validator for Spanish DNI, NIE and entity NIF identifiers.
 *
 * Validation checks format and control characters only; it does not query the
 * Spanish tax administration or prove assignment/current status.
 */
final class SpanishTaxIdValidator implements TaxIdValidatorInterface
{
    private const COUNTRY_CODE = 'ES';

    private const DNI_CONTROL_LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const ENTITY_INITIAL_LETTERS = 'ABCDEFGHJNPQRSUVW';

    private const ENTITY_CONTROL_LETTERS = 'JABCDEFGHI';

    private const ENTITY_DIGIT_CONTROL_ONLY = 'ABEH';

    private const ENTITY_LETTER_CONTROL_ONLY = 'NPQRSW';

    /** {@inheritDoc} */
    public function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }

    /**
     * Normalize and validate a Spanish DNI, NIE or entity NIF.
     *
     * Only format and control characters are checked. A VALID result does not
     * establish issuance, identity or current administrative status.
     *
     * @param string $taxId Identifier supplied by the caller.
     * @return ValidationResult Validation outcome and normalized identifier.
     */
    public function validate(string $taxId): ValidationResult
    {
        $taxId = self::normalize($taxId);

        if ($taxId === '') {
            return $this->invalid($taxId, message: 'Tax ID cannot be empty.');
        }

        if (preg_match('/^[0-9]{8}[A-Z]$/', $taxId) === 1) {
            return $this->validateDni($taxId);
        }

        if (preg_match('/^[XYZ][0-9]{7}[A-Z]$/', $taxId) === 1) {
            return $this->validateNie($taxId);
        }

        if (preg_match('/^[' . self::ENTITY_INITIAL_LETTERS . '][0-9]{7}[0-9A-J]$/', $taxId) === 1) {
            return $this->validateEntityNif($taxId);
        }

        return $this->invalid(
            $taxId,
            message: 'Unsupported Spanish tax ID format.',
        );
    }

    /**
     * Validate the control letter of a normalized DNI.
     *
     * @param string $taxId Nine-character DNI that matches the expected format.
     * @return ValidationResult DNI validation outcome.
     */
    private function validateDni(string $taxId): ValidationResult
    {
        $number = (int) substr($taxId, 0, 8);
        $expectedControl = self::DNI_CONTROL_LETTERS[$number % 23];
        $actualControl = $taxId[8];

        if ($actualControl !== $expectedControl) {
            return $this->invalid(
                $taxId,
                type: 'DNI',
                message: 'Invalid DNI control letter.',
            );
        }

        return $this->valid($taxId, 'DNI');
    }

    /**
     * Validate the control letter of a normalized NIE.
     *
     * Converts initial X, Y or Z into the corresponding numeric prefix before
     * applying the same modulus-23 control as a DNI.
     *
     * @param string $taxId Nine-character NIE matching the expected format.
     * @return ValidationResult NIE validation outcome.
     * @throws LogicException If an unexpected NIE prefix reaches this method.
     */
    private function validateNie(string $taxId): ValidationResult
    {
        $prefixValue = match ($taxId[0]) {
            'X' => '0',
            'Y' => '1',
            'Z' => '2',
            default => throw new LogicException('Unexpected NIE prefix.'),
        };

        $number = (int) ($prefixValue . substr($taxId, 1, 7));
        $expectedControl = self::DNI_CONTROL_LETTERS[$number % 23];
        $actualControl = $taxId[8];

        if ($actualControl !== $expectedControl) {
            return $this->invalid(
                $taxId,
                type: 'NIE',
                message: 'Invalid NIE control letter.',
            );
        }

        return $this->valid($taxId, 'NIE');
    }

    /**
     * Validate the control digit or letter of a Spanish entity NIF.
     *
     * The permitted control character depends on the initial entity letter.
     *
     * @param string $taxId Nine-character entity NIF in the expected format.
     * @return ValidationResult Entity NIF validation outcome.
     */
    private function validateEntityNif(string $taxId): ValidationResult
    {
        $initial = $taxId[0];
        $digits = substr($taxId, 1, 7);
        $actualControl = $taxId[8];

        $controlDigit = self::calculateEntityControlDigit($digits);
        $expectedDigit = (string) $controlDigit;
        $expectedLetter = self::ENTITY_CONTROL_LETTERS[$controlDigit];

        $valid = match (true) {
            str_contains(self::ENTITY_DIGIT_CONTROL_ONLY, $initial) => $actualControl === $expectedDigit,
            str_contains(self::ENTITY_LETTER_CONTROL_ONLY, $initial) => $actualControl === $expectedLetter,
            default => $actualControl === $expectedDigit || $actualControl === $expectedLetter,
        };

        if (!$valid) {
            return $this->invalid(
                $taxId,
                type: 'NIF',
                message: 'Invalid entity NIF control character.',
            );
        }

        return $this->valid($taxId, 'NIF');
    }

    /**
     * Calculate the modulus-10 control digit for an entity NIF.
     *
     * Alternating positions are doubled and their decimal digits summed.
     *
     * @param string $digits Seven digits following the entity letter.
     * @return int Calculated control digit, from 0 to 9.
     */
    private static function calculateEntityControlDigit(string $digits): int
    {
        $sum = 0;

        for ($index = 0; $index < 7; $index++) {
            $digit = (int) $digits[$index];

            if ($index % 2 === 0) {
                $doubled = $digit * 2;
                $sum += intdiv($doubled, 10) + ($doubled % 10);
                continue;
            }

            $sum += $digit;
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Uppercase the identifier and remove whitespace, dots and hyphens.
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
     * Build a successful validation result for a normalized identifier.
     *
     * @param string $taxId Normalized identifier.
     * @param string $type Recognized identifier type (DNI, NIE or NIF).
     * @return ValidationResult Result with VALID status.
     */
    private function valid(string $taxId, string $type): ValidationResult
    {
        return new ValidationResult(
            status: ValidationStatus::VALID,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            type: $type,
        );
    }

    /**
     * Build an invalid validation result without further checks.
     *
     * @param string $taxId Normalized identifier.
     * @param string|null $type Recognized type, if available.
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
