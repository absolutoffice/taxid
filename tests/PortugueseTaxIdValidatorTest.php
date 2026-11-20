<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/TaxId/Contract/TaxIdValidatorInterface.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationStatus.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationResult.php';
require_once __DIR__ . '/../src/TaxId/Country/PT/PortugueseTaxIdValidator.php';

use TaxId\Country\PT\PortugueseTaxIdValidator;
use TaxId\Result\ValidationStatus;

$validator = new PortugueseTaxIdValidator();
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$validCases = [
    '501442600',
    '503504564',
    '123456789',
    '245716840',
];

foreach ($validCases as $taxId) {
    $result = $validator->validate($taxId);
    $assert($result->status === ValidationStatus::VALID, sprintf('%s should be valid.', $taxId));
    $assert($result->countryCode === 'PT', sprintf('%s should have country PT.', $taxId));
    $assert($result->type === 'NIF', sprintf('%s should have type NIF.', $taxId));
}

$normalized = $validator->validate(' 501 442 600 ');
$assert($normalized->status === ValidationStatus::VALID, 'Formatted Portuguese NIF should be valid.');
$assert($normalized->taxId === '501442600', 'Tax ID should be normalized to 501442600.');

$invalidCases = [
    ['', null],
    ['501442601', 'NIF'],
    ['000000000', 'NIF'],
    ['50144260', null],
    ['5014426000', null],
    ['PT501442600', null],
    ['ABCDEFGHI', null],
];

foreach ($invalidCases as [$taxId, $type]) {
    $result = $validator->validate($taxId);
    $assert($result->status === ValidationStatus::INVALID, sprintf('%s should be invalid.', $taxId === '' ? '<empty>' : $taxId));
    $assert($result->type === $type, sprintf('%s should have expected invalid type.', $taxId === '' ? '<empty>' : $taxId));
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    throw new RuntimeException('One or more assertions failed.');
}

fwrite(STDOUT, sprintf("OK: %d validation cases passed.\n", count($validCases) + count($invalidCases)));
