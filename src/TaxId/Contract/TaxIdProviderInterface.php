<?php

declare(strict_types=1);

namespace TaxId\Contract;

use TaxId\Result\LookupResult;

/**
 * Contract for external tax identifier information providers.
 *
 * Providers enrich an identifier with external data. They are deliberately
 * separate from validators so a provider outage, quota limit or missing
 * record never changes the local validity of an identifier.
 */
interface TaxIdProviderInterface
{
    /**
     * Returns the ISO 3166-1 alpha-2 country code handled by the provider.
     */
    public function countryCode(): string;

    /**
     * Looks up external information associated with a tax identifier.
     */
    public function lookup(string $taxId): LookupResult;
}
