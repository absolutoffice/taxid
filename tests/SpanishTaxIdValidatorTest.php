<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/TaxId/Contract/TaxIdValidatorInterface.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationStatus.php';
require_once __DIR__ . '/../src/TaxId/Result/ValidationResult.php';
require_once __DIR__ . '/../src/TaxId/Country/ES/SpanishTaxIdValidator.php';

use TaxId\Country\ES\SpanishTaxIdValidator;
use TaxId\Result\ValidationStatus;

$validator = new SpanishTaxIdValidator();
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$validCases = [
    ['12345678Z', 'DNI'],
    ['12.345.678-z', 'DNI'],
    ['X1234567L', 'NIE'],
    ['G86106630', 'NIF'],
    ['Q2828005E', 'NIF'],
    ['R2800395B', 'NIF'],
    ['U16854101', 'NIF'],
    ['N0037022A', 'NIF'],
    ['P3303400J', 'NIF'],
];

foreach ($validCases as [$taxId, $type]) {
    $result = $validator->validate($taxId);
    $assert($result->status === ValidationStatus::VALID, sprintf('%s should be valid.', $taxId));
    $assert($result->countryCode === 'ES', sprintf('%s should have country ES.', $taxId));
    $assert($result->type === $type, sprintf('%s should have type %s.', $taxId, $type));
}

$normalized = $validator->validate(' 12.345.678-z ');
$assert($normalized->taxId === '12345678Z', 'Tax ID should be normalized to 12345678Z.');

$invalidCases = [
    ['', null],
    ['12345678A', 'DNI'],
    ['X1234567A', 'NIE'],
    ['G86106631', 'NIF'],
    ['Q28280055', 'NIF'],
    ['ES12345678Z', null],
    ['1234', null],
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
