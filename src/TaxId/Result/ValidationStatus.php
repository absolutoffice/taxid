<?php

declare(strict_types=1);

namespace TaxId\Result;

/**
 * Outcome of a local tax identifier validation.
 */
enum ValidationStatus: string
{
    /** The identifier was checked and satisfies the implemented rules. */
    case VALID = 'valid';

    /** The identifier was checked and fails the implemented rules. */
    case INVALID = 'invalid';

    /** No validator is registered for the requested country. */
    case NOT_SUPPORTED = 'not_supported';
}
