<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a class and records its creator as the owning teacher (class_teachers),
 * in one transaction. The class_teachers link is the basis of the sidebar's
 * "only my classes" principle.
 */
class ClassService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $owner): SchoolClass
    {
        return DB::transaction(function () use ($attributes, $owner): SchoolClass {
            $class = SchoolClass::create($attributes);

            $class->teachers()->attach($owner, ['role' => 'owner']);

            return $class;
        });
    }
}
