<?php

namespace App\Services\Assessment\Advanced;

use RuntimeException;

/**
 * Raised when something asks for an advanced reading of a class and the organization does
 * not hold `advanced_analytics`.
 *
 * THE SERVICE REFUSES, NOT ONLY THE ROUTE. The route's `module:advanced_analytics` and the
 * controllers that strip a key from a shared page are the gates a teacher meets. This is the
 * last one, and it is the one that cannot be forgotten: a controller that built the timeline
 * without checking would be a Pro reading handed to a Base organization, and the data the
 * reading is made of is exactly the data the Base plan is entitled to see — only the
 * interpretation is reserved. A service that does not ask the question cannot be misused by
 * accident; this one asks.
 */
final class AdvancedAnalyticsUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A análise avançada da avaliação não está incluída no plano desta organização.');
    }
}
