<?php

namespace App\Actions\Entitlements;

use App\Models\Module;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

trait ResolvesCapabilityModules
{
    /**
     * @param  list<string>  $keys
     * @return Collection<int, Module>
     */
    private function modulesFor(array $keys): Collection
    {
        $unique = array_values(array_unique($keys));
        $modules = Module::query()->whereIn('key', $unique)->get();
        $found = $modules->pluck('key')->all();
        $missing = array_values(array_diff($unique, $found));
        if ($unique === [] || $missing !== []) {
            throw ValidationException::withMessages(['module_keys' => $unique === []
                ? __('Escolha pelo menos uma capacidade.')
                : __('As capacidades seguintes não existem: :keys.', ['keys' => implode(', ', $missing)])]);
        }

        return $modules;
    }
}
