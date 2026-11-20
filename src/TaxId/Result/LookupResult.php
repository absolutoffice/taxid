<?php

declare(strict_types=1);

namespace TaxId\Result;

/**
 * Immutable result of an external tax identifier lookup.
 */
final class LookupResult
{
    /**
     * Create an immutable external lookup outcome.
     *
     * @param LookupStatus $status Outcome of the provider lookup.
     * @param string $countryCode ISO 3166-1 alpha-2 country code.
     * @param string $taxId Identifier used by the provider (typically normalized).
     * @param array<string, mixed> $data Associated entity fields or provider metadata.
     * @param string|null $provider Provider identifier, when available.
     * @param string|null $message Diagnostic text, when applicable.
     */
    public function __construct(
        public readonly LookupStatus $status,
        public readonly string $countryCode,
        public readonly string $taxId,
        public readonly array $data = [],
        public readonly ?string $provider = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * Determine whether the provider returned associated entity data.
     *
     * @return bool True only for a FOUND result.
     */
    public function isFound(): bool
    {
        return $this->status === LookupStatus::FOUND;
    }

    /**
     * Determine whether the lookup returned no associated entity data.
     *
     * @return bool True only for a NO_DATA result.
     */
    public function hasNoData(): bool
    {
        return $this->status === LookupStatus::NO_DATA;
    }

    /**
     * Determine whether the external provider reported a request/quota limit.
     *
     * @return bool True only for a RATE_LIMITED result.
     */
    public function isRateLimited(): bool
    {
        return $this->status === LookupStatus::RATE_LIMITED;
    }

    /**
     * Determine whether the lookup failed.
     *
     * Includes provider, network and response errors.
     *
     * @return bool True only for an ERROR result.
     */
    public function isError(): bool
    {
        return $this->status === LookupStatus::ERROR;
    }

    /**
     * Determine whether a lookup provider supports the requested country.
     *
     * This can be true even when the lookup fails or returns no data.
     *
     * @return bool False only for a NOT_SUPPORTED result.
     */
    public function isSupported(): bool
    {
        return $this->status !== LookupStatus::NOT_SUPPORTED;
    }
}
