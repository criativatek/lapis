<?php

namespace App\Support\Landing;

use App\Models\Module;
use App\Models\Plan;

/**
 * The plan cards the public pages render — the landing and /planos read the
 * same thing, so it lives here rather than in either controller.
 */
class PlanCards
{
    /**
     * @return list<array{key: string, name: string, moduleKeys: list<string>}>
     */
    public static function all(): array
    {
        $cards = [];

        foreach (Plan::with('currentVersion.modules')->orderBy('sort_order')->get() as $plan) {
            // A plan with nothing published yet shows no capabilities rather
            // than blowing up a public page. It cannot happen in a seeded
            // installation, and a page that 500s is the worse of the two
            // failures.
            $version = $plan->currentVersionOrNull();

            $cards[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'moduleKeys' => $version === null ? [] : array_values(
                    $version->modules->map(fn (Module $module): string => $module->key)->all(),
                ),
            ];
        }

        return $cards;
    }
}
