<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a tenant-scoped query runs with no organization resolved.
 *
 * This is deliberately loud. A silent fallback here would return rows from
 * every organization at once, which for student data is a breach, not a bug.
 * Console commands and queued jobs must declare their tenant via
 * CurrentOrganization::runFor().
 */
class TenantNotResolvedException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No organization is resolved for the current context. '
            .'HTTP requests resolve one via the ResolveOrganization middleware; '
            .'jobs, commands and tests must wrap the work in CurrentOrganization::runFor().'
        );
    }
}
