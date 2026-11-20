<?php

declare(strict_types=1);

namespace TaxId\Country\PT;

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use TaxId\Contract\TaxIdProviderInterface;
use TaxId\Result\LookupResult;
use TaxId\Result\LookupStatus;
use Throwable;

/**
 * External Portuguese NIF lookup provider backed by nif.pt.
 *
 * This provider enriches a locally validated NIF with entity information when
 * available. Missing entity data, rate limits and provider errors are modeled
 * explicitly through {@see LookupStatus} and never alter local NIF validity.
 */
final class NifPtProvider implements TaxIdProviderInterface
{
    private const COUNTRY_CODE = 'PT';

    private const PROVIDER = 'nif.pt';

    private const DEFAULT_BASE_URL = 'https://www.nif.pt/';

    private const DEFAULT_TIMEOUT_SECONDS = 5;

    /** @var Closure(string, int): string */
    private readonly Closure $httpGet;

    /**
     * Configure access to the external nif.pt lookup service.
     *
     * @param string $apiKey Non-empty nif.pt API key.
     * @param string $baseUrl HTTPS endpoint base URL.
     * @param int $timeoutSeconds HTTP timeout in seconds (at least 1).
     * @param null|callable(string, int): string $httpGet Optional HTTP transport
     *        receiving the URL and timeout and returning the response body;
     *        primarily useful for deterministic tests.
     * @throws InvalidArgumentException If the key, URL or timeout is invalid.
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?callable $httpGet = null,
    ) {
        if (trim($this->apiKey) === '') {
            throw new InvalidArgumentException('nif.pt API key cannot be empty.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('nif.pt timeout must be at least 1 second.');
        }

        if (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid nif.pt base URL.');
        }

        $scheme = strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('nif.pt base URL must use HTTPS.');
        }

        $this->httpGet = $httpGet !== null
            ? Closure::fromCallable($httpGet)
            : Closure::fromCallable([$this, 'nativeHttpGet']);
    }

    /** {@inheritDoc} */
    public function countryCode(): string
    {
        return self::COUNTRY_CODE;
    }

    /**
     * Look up associated entity information for a Portuguese NIF.
     *
     * Returns FOUND with mapped entity data, NO_DATA when no associated entity
     * was found, RATE_LIMITED for quota responses, or ERROR for malformed
     * identifiers, transport failures and unexpected provider responses.
     * Lookup status does not affect the result of local NIF validation.
     *
     * @param string $taxId NIF to look up; separators are removed first.
     * @return LookupResult Lookup outcome, data and optional provider diagnostics.
     */
    public function lookup(string $taxId): LookupResult
    {
        $taxId = self::normalize($taxId);

        if (preg_match('/^[0-9]{9}$/', $taxId) !== 1) {
            return $this->error(
                $taxId,
                'Portuguese NIF lookup requires exactly 9 digits.',
            );
        }

        $query = http_build_query(
            [
                'json' => 1,
                'q' => $taxId,
                'key' => $this->apiKey,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );

        $url = rtrim($this->baseUrl, '/') . '/?' . $query;

        try {
            $body = ($this->httpGet)($url, $this->timeoutSeconds);
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->error(
                $taxId,
                'nif.pt returned an invalid JSON response.',
            );
        } catch (Throwable $exception) {
            if (self::isRateLimitMessage($exception->getMessage())) {
                return $this->rateLimited(
                    $taxId,
                    'nif.pt rate limit reached. Please try again later.',
                );
            }

            return $this->error(
                $taxId,
                'nif.pt request failed.',
            );
        }

        $payload = self::stringKeyedArrayOrNull($decoded);

        if ($payload === null) {
            return $this->error($taxId, 'nif.pt returned an unexpected response.');
        }

        $apiMessage = self::extractApiMessage($payload);

        if ($apiMessage !== null && self::isRateLimitMessage($apiMessage)) {
            return $this->rateLimited(
                $taxId,
                $apiMessage,
                self::responseMeta($payload),
            );
        }

        if (self::isNoDataResponse($payload, $apiMessage)) {
            return $this->noData(
                $taxId,
                $apiMessage ?? 'The NIF is valid, but nif.pt has no associated entity data.',
                self::responseMeta($payload),
            );
        }

        if (($payload['result'] ?? null) !== 'success') {
            return $this->error(
                $taxId,
                $apiMessage ?? 'nif.pt did not return a successful result.',
                self::responseMeta($payload),
            );
        }

        if (($payload['is_nif'] ?? null) === false || ($payload['nif_validation'] ?? null) === false) {
            return $this->error(
                $taxId,
                'nif.pt reports that the supplied NIF is invalid.',
                self::responseMeta($payload),
            );
        }

        $records = $payload['records'] ?? null;

        if (!is_array($records) || $records === []) {
            return $this->noData(
                $taxId,
                'The NIF is valid, but nif.pt has no associated entity data.',
                self::responseMeta($payload),
            );
        }

        $record = self::stringKeyedArrayOrNull($records[$taxId] ?? null);
        if ($record === null || $record === []) {
            return $this->noData(
                $taxId,
                'The NIF is valid, but nif.pt has no associated entity data.',
                self::responseMeta($payload),
            );
        }

        return new LookupResult(
            status: LookupStatus::FOUND,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            data: self::mapRecord($record, $payload),
            provider: self::PROVIDER,
        );
    }

    /**
     * Accept an array only if all its keys are strings.
     *
     * @param mixed $value Value decoded from the provider response.
     * @return array<string, mixed>|null String-keyed array, otherwise null.
     */
    private static function stringKeyedArrayOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $_item) {
            if (!is_string($key)) {
                return null;
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Map a nif.pt record to the library's public lookup data shape.
     *
     * Returns normalized name, address, postal code, contact and geographic
     * fields together with the original record and provider credit metadata.
     * Unreported fields are represented as null where applicable.
     *
     * @param array<string, mixed> $record Provider entity record.
     * @param array<string, mixed> $payload Full provider response for metadata.
     * @return array<string, mixed> Normalized entity fields and source data.
     */
    private static function mapRecord(array $record, array $payload): array
    {
        $postalCode = null;
        $pc4 = self::stringOrNull($record['pc4'] ?? null);
        $pc3 = self::stringOrNull($record['pc3'] ?? null);

        if ($pc4 !== null && $pc3 !== null) {
            $postalCode = $pc4 . '-' . $pc3;
        } elseif ($pc4 !== null) {
            $postalCode = $pc4;
        }

        $contacts = self::stringKeyedArrayOrNull($record['contacts'] ?? null) ?? [];
        $geo = self::stringKeyedArrayOrNull($record['geo'] ?? null) ?? [];

        return [
            'name' => self::stringOrNull($record['title'] ?? null),
            'address' => self::stringOrNull($record['address'] ?? null),
            'postalCode' => $postalCode,
            'email' => self::stringOrNull($contacts['email'] ?? null),
            'state' => self::stringOrNull($geo['region'] ?? null),
            'city' => self::stringOrNull($geo['county'] ?? null)
                ?? self::stringOrNull($record['city'] ?? null),
            'neighborhood' => self::stringOrNull($geo['parish'] ?? null),
            'raw' => $record,
            'providerMeta' => [
                'credits' => is_array($payload['credits'] ?? null)
                    ? $payload['credits']
                    : [],
            ],
        ];
    }

    /**
     * Extract provider validation flags and credit metadata.
     *
     * @param array<string, mixed> $payload Decoded nif.pt response.
     * @return array<string, mixed> isNif, nifValidation and credits entries.
     */
    private static function responseMeta(array $payload): array
    {
        return [
            'isNif' => isset($payload['is_nif']) ? (bool) $payload['is_nif'] : null,
            'nifValidation' => isset($payload['nif_validation']) ? (bool) $payload['nif_validation'] : null,
            'credits' => is_array($payload['credits'] ?? null) ? $payload['credits'] : [],
        ];
    }

    /**
     * Return the first usable diagnostic field from a response.
     *
     * Checks message, error and status in that order and discards empty or
     * non-scalar values.
     *
     * @param array<string, mixed> $payload Decoded response.
     * @return string|null Diagnostic text, if present.
     */
    private static function extractApiMessage(array $payload): ?string
    {
        foreach (['message', 'error', 'status'] as $key) {
            $value = self::stringOrNull($payload[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Determine whether text suggests throttling or a quota error.
     *
     * @param string $message Provider message or transport exception text.
     * @return bool True when a recognized rate-limit phrase is found.
     */
    private static function isRateLimitMessage(string $message): bool
    {
        $message = strtolower(trim($message));

        if ($message === '') {
            return false;
        }

        if (str_contains($message, 'http 429')) {
            return true;
        }

        if (str_contains($message, 'too many requests')) {
            return true;
        }

        if (str_contains($message, 'rate limit')) {
            return true;
        }

        return preg_match('/\blimit\b.*\breached\b/i', $message) === 1;
    }

    /**
     * Recognize successful validation with no associated entity data.
     *
     * Accepts empty records with positive NIF flags or a known no-data message.
     *
     * @param array<string, mixed> $payload Decoded response.
     * @param string|null $message Provider diagnostic, when available.
     * @return bool True when the response indicates missing entity data.
     */
    private static function isNoDataResponse(array $payload, ?string $message): bool
    {
        $records = $payload['records'] ?? null;
        $recordsEmpty = is_array($records) && $records === [];

        $isNif = $payload['is_nif'] ?? null;
        $nifValidation = $payload['nif_validation'] ?? null;

        if (
            $recordsEmpty
            && ($isNif === true || $nifValidation === true)
        ) {
            return true;
        }

        if ($message === null) {
            return false;
        }

        $message = self::normalizeMessage($message);

        foreach (
            [
            'nao conseguimos determinar a entidade associada',
            'nif valido mas nao conseguimos determinar a entidade associada',
            'valid nif but no associated entity',
            'valid nif but no associated entity data',
            'no associated entity data',
            'no records found',
            ] as $needle
        ) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a message for accent-insensitive comparison.
     *
     * Lowercases, replaces common Portuguese diacritics and collapses spaces.
     *
     * @param string $message Original provider message.
     * @return string Normalized text for matching.
     */
    private static function normalizeMessage(string $message): string
    {
        $message = strtolower(trim($message));
        $message = strtr($message, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);

        return preg_replace('/\s+/u', ' ', $message) ?? $message;
    }

    /**
     * Convert a non-empty scalar value to trimmed text.
     *
     * @param mixed $value Field from the provider response.
     * @return string|null Trimmed text, or null for empty/non-scalar values.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Remove whitespace, dots and hyphens from a Portuguese NIF.
     *
     * @param string $taxId Input identifier.
     * @return string Normalized identifier.
     */
    private static function normalize(string $taxId): string
    {
        $taxId = trim($taxId);
        $normalized = preg_replace('/[\s.\-]+/u', '', $taxId);

        return $normalized ?? $taxId;
    }

    /**
     * Create a lookup result without associated entity data.
     *
     * @param string $taxId Normalized NIF.
     * @param string $message Explanation returned to the caller.
     * @param array<string, mixed> $data Optional provider metadata.
     * @return LookupResult Result with NO_DATA status.
     */
    private function noData(string $taxId, string $message, array $data = []): LookupResult
    {
        return new LookupResult(
            status: LookupStatus::NO_DATA,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            data: $data,
            provider: self::PROVIDER,
            message: $message,
        );
    }

    /**
     * Create a result indicating an external service rate limit.
     *
     * @param string $taxId Normalized NIF.
     * @param string $message Explanation returned to the caller.
     * @param array<string, mixed> $data Optional provider metadata.
     * @return LookupResult Result with RATE_LIMITED status.
     */
    private function rateLimited(string $taxId, string $message, array $data = []): LookupResult
    {
        return new LookupResult(
            status: LookupStatus::RATE_LIMITED,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            data: $data,
            provider: self::PROVIDER,
            message: $message,
        );
    }

    /**
     * Create an external lookup error result without throwing it.
     *
     * @param string $taxId Normalized NIF.
     * @param string $message Explanation returned to the caller.
     * @param array<string, mixed> $data Optional provider metadata.
     * @return LookupResult Result with ERROR status.
     */
    private function error(string $taxId, string $message, array $data = []): LookupResult
    {
        return new LookupResult(
            status: LookupStatus::ERROR,
            countryCode: self::COUNTRY_CODE,
            taxId: $taxId,
            data: $data,
            provider: self::PROVIDER,
            message: $message,
        );
    }

    /**
     * Send a GET request using PHP streams and return its body.
     *
     * A missing response or HTTP status outside the 2xx range is treated as an
     * error; lookup() converts this exception into a LookupResult.
     *
     * @param string $url Request URL including provider query parameters.
     * @param int $timeoutSeconds Network timeout in seconds.
     * @return string Response body.
     * @throws RuntimeException If the request fails or HTTP status is not 2xx.
     */
    private function nativeHttpGet(string $url, int $timeoutSeconds): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => [
                    'Accept: application/json',
                    'User-Agent: TaxId/1.0',
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException('HTTP request failed.');
        }

        // Available since PHP 8.4; avoids the deprecated $http_response_header in PHP 8.5.
        $headers = http_get_last_response_headers() ?? [];

        $statusCode = self::extractHttpStatusCode($headers);
        if ($statusCode !== null && ($statusCode < 200 || $statusCode >= 300)) {
            throw new RuntimeException(sprintf('HTTP %d returned by nif.pt.', $statusCode));
        }

        return $body;
    }

    /**
     * Extract the status code from an HTTP response header list.
     *
     * @param list<string> $headers Headers supplied by PHP's HTTP wrapper.
     * @return int|null HTTP status code, or null when no status line was found.
     */
    private static function extractHttpStatusCode(array $headers): ?int
    {
        $statusCode = null;

        // The stream wrapper can return several status lines after redirects.
        // The final response code is the one that determines the outcome.
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $header, $matches) === 1) {
                $statusCode = (int) $matches[1];
            }
        }

        return $statusCode;
    }
}
