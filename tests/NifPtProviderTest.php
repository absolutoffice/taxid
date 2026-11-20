<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/TaxId/Contract/TaxIdProviderInterface.php';
require_once __DIR__ . '/../src/TaxId/Result/LookupStatus.php';
require_once __DIR__ . '/../src/TaxId/Result/LookupResult.php';
require_once __DIR__ . '/../src/TaxId/Country/PT/NifPtProvider.php';

use TaxId\Country\PT\NifPtProvider;
use TaxId\Result\LookupStatus;

$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$capturedUrl = null;
$capturedTimeout = null;

$successTransport = static function (string $url, int $timeout) use (&$capturedUrl, &$capturedTimeout): string {
    $capturedUrl = $url;
    $capturedTimeout = $timeout;

    return json_encode([
        'result' => 'success',
        'records' => [
            '509442013' => [
                'nif' => 509442013,
                'title' => 'Nexperience Lda',
                'address' => 'Rua da Lionesa N 446, Edificio G20',
                'pc4' => '4465',
                'pc3' => '671',
                'city' => 'Leca do Balio',
                'activity' => 'Desenvolvimento de software.',
                'status' => 'active',
                'cae' => '62010',
                'contacts' => ['email' => 'info@example.test'],
                'structure' => ['nature' => 'LDA'],
                'geo' => [
                    'region' => 'Porto',
                    'county' => 'Matosinhos',
                    'parish' => 'Leça do Balio',
                ],
                'place' => ['address' => 'Rua da Lionesa N 446, Edificio G20'],
            ],
        ],
        'nif_validation' => true,
        'is_nif' => true,
        'credits' => ['used' => 'free', 'left' => []],
    ], JSON_THROW_ON_ERROR);
};

$provider = new NifPtProvider(
    apiKey: 'test-key',
    timeoutSeconds: 7,
    httpGet: $successTransport,
);

$result = $provider->lookup(' 509 442 013 ');

$assert($result->status === LookupStatus::FOUND, 'Company fixture should produce FOUND.');
$assert($result->isFound(), 'FOUND helper should return true.');
$assert(!$result->hasNoData(), 'FOUND result must not report NO_DATA.');
$assert(!$result->isRateLimited(), 'FOUND result must not report RATE_LIMITED.');
$assert(!$result->isError(), 'FOUND result must not report ERROR.');
$assert($result->isSupported(), 'nif.pt result should be supported.');
$assert($result->countryCode === 'PT', 'Country code should be PT.');
$assert($result->taxId === '509442013', 'NIF should be normalized.');
$assert($result->provider === 'nif.pt', 'Provider should be nif.pt.');
$assert(($result->data['name'] ?? null) === 'Nexperience Lda', 'Company name should be mapped.');
$assert(($result->data['postalCode'] ?? null) === '4465-671', 'Postal code should be mapped.');
$assert(($result->data['email'] ?? null) === 'info@example.test', 'Email should be mapped.');
$assert(($result->data['state'] ?? null) === 'Porto', 'Region should be mapped to state.');
$assert(($result->data['city'] ?? null) === 'Matosinhos', 'County should be mapped to city.');
$assert(($result->data['neighborhood'] ?? null) === 'Leça do Balio', 'Parish should be mapped to neighborhood.');
$assert(is_array($result->data['raw'] ?? null), 'Raw provider record should be preserved.');
$assert($capturedTimeout === 7, 'Configured timeout should reach the transport.');
$assert(is_string($capturedUrl) && str_starts_with($capturedUrl, 'https://www.nif.pt/?'), 'Provider should use HTTPS nif.pt endpoint.');
$assert(is_string($capturedUrl) && str_contains($capturedUrl, 'json=1'), 'Request should ask for JSON.');
$assert(is_string($capturedUrl) && str_contains($capturedUrl, 'q=509442013'), 'Request should contain normalized NIF.');
$assert(is_string($capturedUrl) && str_contains($capturedUrl, 'key=test-key'), 'Request should contain API key.');

$noDataProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'success',
        'records' => [],
        'nif_validation' => true,
        'is_nif' => true,
        'credits' => ['used' => 'free'],
    ], JSON_THROW_ON_ERROR),
);

$noData = $noDataProvider->lookup('501442600');
$assert($noData->status === LookupStatus::NO_DATA, 'Valid NIF without entity data should produce NO_DATA.');
$assert(!$noData->isFound(), 'NO_DATA must not report FOUND.');
$assert($noData->hasNoData(), 'NO_DATA helper should return true.');
$assert(!$noData->isRateLimited(), 'NO_DATA must not be treated as RATE_LIMITED.');
$assert(!$noData->isError(), 'NO_DATA must not be treated as ERROR.');
$assert($noData->isSupported(), 'NO_DATA still comes from a supported provider.');
$assert($noData->provider === 'nif.pt', 'NO_DATA should preserve provider.');
$assert(($noData->data['isNif'] ?? null) === true, 'NO_DATA should preserve positive NIF validation metadata.');

$noEntityMessageProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'error',
        'message' => 'O NIF indicado é válido mas não conseguimos determinar a entidade associada.',
        'nif_validation' => true,
        'is_nif' => true,
        'records' => [],
    ], JSON_THROW_ON_ERROR),
);

$noEntityMessage = $noEntityMessageProvider->lookup('501442600');
$assert($noEntityMessage->status === LookupStatus::NO_DATA, 'Valid NIF without associated entity must produce NO_DATA even when nif.pt result is not success.');
$assert($noEntityMessage->hasNoData(), 'No-entity response should report NO_DATA.');
$assert(!$noEntityMessage->isError(), 'No-entity response must not be treated as ERROR.');
$assert($noEntityMessage->message === 'O NIF indicado é válido mas não conseguimos determinar a entidade associada.', 'No-entity provider message should be preserved.');

$noEntityMessageOnlyProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'error',
        'message' => 'O NIF indicado é válido mas não conseguimos determinar a entidade associada.',
    ], JSON_THROW_ON_ERROR),
);

$noEntityMessageOnly = $noEntityMessageOnlyProvider->lookup('501442600');
$assert($noEntityMessageOnly->status === LookupStatus::NO_DATA, 'Known nif.pt no-entity message should produce NO_DATA even without validation flags.');

$noRecordsFoundProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'error',
        'message' => 'No records found',
        'nif_validation' => true,
        'is_nif' => true,
        'credits' => [
            'used' => 'free',
            'left' => [
                'month' => 991,
                'day' => 91,
                'hour' => 9,
                'minute' => 0,
                'paid' => 0,
            ],
        ],
    ], JSON_THROW_ON_ERROR),
);

$noRecordsFound = $noRecordsFoundProvider->lookup('501442600');
$assert($noRecordsFound->status === LookupStatus::NO_DATA, 'Real nif.pt "No records found" response for a valid NIF must produce NO_DATA.');
$assert($noRecordsFound->hasNoData(), '"No records found" should report NO_DATA.');
$assert(!$noRecordsFound->isError(), '"No records found" for a valid NIF must not be treated as ERROR.');
$assert(($noRecordsFound->data['isNif'] ?? null) === true, '"No records found" should preserve is_nif=true.');
$assert(($noRecordsFound->data['nifValidation'] ?? null) === true, '"No records found" should preserve nif_validation=true.');
$assert($noRecordsFound->message === 'No records found', 'Provider message should be preserved for NO_DATA.');

$rateLimitedProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'error',
        'message' => 'Limit per minute reached. Please, try again later or buy credits.',
    ], JSON_THROW_ON_ERROR),
);

$rateLimited = $rateLimitedProvider->lookup('509442013');
$assert($rateLimited->status === LookupStatus::RATE_LIMITED, 'nif.pt quota response should produce RATE_LIMITED.');
$assert($rateLimited->isRateLimited(), 'RATE_LIMITED helper should return true.');
$assert(!$rateLimited->isFound(), 'RATE_LIMITED must not report FOUND.');
$assert(!$rateLimited->hasNoData(), 'RATE_LIMITED must not report NO_DATA.');
$assert(!$rateLimited->isError(), 'RATE_LIMITED must not be collapsed into ERROR.');
$assert($rateLimited->isSupported(), 'RATE_LIMITED still comes from a supported provider.');
$assert($rateLimited->message === 'Limit per minute reached. Please, try again later or buy credits.', 'Provider rate-limit message should be preserved.');

$http429Provider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static function (string $url, int $timeout): string {
        throw new RuntimeException('HTTP 429 returned by nif.pt.');
    },
);

$http429 = $http429Provider->lookup('509442013');
$assert($http429->status === LookupStatus::RATE_LIMITED, 'HTTP 429 transport failure should produce RATE_LIMITED.');
$assert($http429->message === 'nif.pt rate limit reached. Please try again later.', 'HTTP 429 should expose a stable message without request details.');

$invalidFormat = $provider->lookup('PT509442013');
$assert($invalidFormat->status === LookupStatus::ERROR, 'Non-9-digit input should produce ERROR before HTTP transport.');

$invalidNifProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => json_encode([
        'result' => 'success',
        'records' => [],
        'nif_validation' => false,
        'is_nif' => false,
        'credits' => ['used' => 'free'],
    ], JSON_THROW_ON_ERROR),
);

$invalidNif = $invalidNifProvider->lookup('123456780');
$assert($invalidNif->status === LookupStatus::ERROR, 'nif.pt invalid-NIF response should produce ERROR.');
$assert(($invalidNif->data['isNif'] ?? null) === false, 'Invalid response metadata should preserve is_nif.');

$badJsonProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static fn (string $url, int $timeout): string => '{bad-json',
);

$badJson = $badJsonProvider->lookup('509442013');
$assert($badJson->status === LookupStatus::ERROR, 'Malformed JSON should produce ERROR gracefully.');
$assert($badJson->message === 'nif.pt returned an invalid JSON response.', 'Malformed JSON should have a stable message.');

$requestFailureProvider = new NifPtProvider(
    apiKey: 'test-key',
    httpGet: static function (string $url, int $timeout): string {
        throw new RuntimeException('network down');
    },
);

$requestFailure = $requestFailureProvider->lookup('509442013');
$assert($requestFailure->status === LookupStatus::ERROR, 'Transport exception should produce ERROR gracefully.');
$assert($requestFailure->message === 'nif.pt request failed.', 'Transport errors should not expose request details or secrets.');

// HTTP wrappers can provide multiple status lines when following redirects.
// The last status must win, even when the initial response was a redirect.
$statusParser = new ReflectionMethod(NifPtProvider::class, 'extractHttpStatusCode');
$redirectedStatus = $statusParser->invoke(null, [
    'HTTP/1.1 301 Moved Permanently',
    'Location: https://www.nif.pt/',
    'HTTP/2 429 Too Many Requests',
]);
$assert($redirectedStatus === 429, 'Final HTTP status should win after redirects.');
$assert(
    $statusParser->invoke(null, ['Date: Thu, 19 Nov 2026 00:00:00 GMT']) === null,
    'Missing HTTP status should be reported as null.',
);

$emptyKeyRejected = false;
try {
    new NifPtProvider(apiKey: '');
} catch (InvalidArgumentException) {
    $emptyKeyRejected = true;
}
$assert($emptyKeyRejected, 'Empty API key should be rejected at construction time.');

$httpBaseUrlRejected = false;
try {
    new NifPtProvider(apiKey: 'test-key', baseUrl: 'http://www.nif.pt/');
} catch (InvalidArgumentException) {
    $httpBaseUrlRejected = true;
}
$assert($httpBaseUrlRejected, 'Non-HTTPS base URL should be rejected.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }

    throw new RuntimeException('One or more assertions failed.');
}

fwrite(STDOUT, "OK: nif.pt provider tests passed.\n");
