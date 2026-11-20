<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/TaxId/Contract/TaxIdValidatorInterface.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationStatus.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationResult.php';
require_once __DIR__ . '/../src/TaxId/Country/AD/AndorranTaxIdValidator.php';

use TaxId\Country\AD\AndorranTaxIdValidator;
use TaxId\Result\ValidationStatus;

$validator = new AndorranTaxIdValidator();

/** @var list<array{0: string, 1: bool, 2: string}> $cases */
$cases = [
    ['D-059888-N', true, 'D059888N'],
    ['d 059888 n', true, 'D059888N'],
    ['D800043L', true, 'D800043L'],
    ['800649P', true, '800649P'],
    ['061551T', true, '061551T'],
    ['', false, ''],
    ['12345A', false, '12345A'],
    ['1234567A', false, '1234567A'],
    ['DD059888N', false, 'DD059888N'],
    ['D059888', false, 'D059888'],
    ['D05988-N', false, 'D05988N'],
    ['D0598881', false, 'D0598881'],
];

foreach ($cases as $index => [$input, $expectedValid, $expectedTaxId]) {
    $result = $validator->validate($input);

    $expectedStatus = $expectedValid
        ? ValidationStatus::VALID
        : ValidationStatus::INVALID;

    if ($result->status !== $expectedStatus) {
        throw new RuntimeException(sprintf(
            'Case %d failed for %s: expected status=%s, got %s.',
            $index + 1,
            var_export($input, true),
            $expectedStatus->value,
            $result->status->value,
        ));
    }

    if ($result->countryCode !== 'AD') {
        throw new RuntimeException(sprintf('Case %d returned the wrong country code.', $index + 1));
    }

    if ($result->taxId !== $expectedTaxId) {
        throw new RuntimeException(sprintf(
            'Case %d normalization failed: expected %s, got %s.',
            $index + 1,
            var_export($expectedTaxId, true),
            var_export($result->taxId, true),
        ));
    }

    if ($input !== '' && $result->type !== 'NRT') {
        throw new RuntimeException(sprintf('Case %d returned the wrong type.', $index + 1));
    }
}

printf("OK: %d Andorran validation cases passed.\n", count($cases));
