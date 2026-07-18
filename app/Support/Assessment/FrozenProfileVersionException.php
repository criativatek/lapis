<?php

namespace App\Support\Assessment;

use App\Models\AssessmentProfileVersion;
use RuntimeException;

/**
 * Thrown when something tries to modify a frozen profile version.
 *
 * A frozen version is the historical rule a result was calculated under. Changing
 * it would silently rewrite what "level 3, 1.º semestre" meant after the fact.
 * Editing an active profile must create a new draft version instead.
 */
class FrozenProfileVersionException extends RuntimeException
{
    public static function make(AssessmentProfileVersion $version): self
    {
        return new self(
            "Assessment profile version {$version->id} is frozen and cannot be modified. "
            .'Create a new draft version to make changes.'
        );
    }
}
