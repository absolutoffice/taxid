<?php

declare(strict_types=1);

namespace TaxId\Result;

/**
 * Outcome of an external tax identifier lookup.
 */
enum LookupStatus: string
{
    /** The provider returned associated entity data. */
    case FOUND = 'found';

    /** The lookup completed but the provider has no associated entity data. */
    case NO_DATA = 'no_data';

    /** The provider rejected the lookup because a request/quota limit was reached. */
    case RATE_LIMITED = 'rate_limited';

    /** The lookup failed because of a provider, transport or response error. */
    case ERROR = 'error';

    /** No provider is registered for the requested country. */
    case NOT_SUPPORTED = 'not_supported';
}
