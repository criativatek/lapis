<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Entitlements\ResolvesCapabilityModules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCapabilityPresetRequest;
use App\Models\CapabilityGrantPreset;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminCapabilityPresetController extends Controller
{
    use ResolvesCapabilityModules;

    public function store(StoreCapabilityPresetRequest $request, AuditLog $audit): RedirectResponse
    {
        $data = $request->validated();
        $modules = $this->modulesFor($data['module_keys']);
        $preset = CapabilityGrantPreset::create(['key' => $data['key'], 'name' => $data['name'], 'notes' => $data['notes'] ?? null, 'default_duration_days' => $data['default_duration_days'] ?? null, 'active' => true, 'created_by' => $request->user()?->getKey()]);
        $preset->modules()->attach($modules->modelKeys());
        $audit->recordPlatform('capability.preset_created', $request->user(), 'Preset de capacidades criado.', ['preset_id' => $preset->getKey(), 'module_keys' => $data['module_keys']]);

        return back();
    }

    public function toggle(Request $request, CapabilityGrantPreset $preset, AuditLog $audit): RedirectResponse
    {
        $preset->active = ! $preset->active;
        $preset->save();
        $audit->recordPlatform('capability.preset_toggled', $request->user(), 'Estado do preset alterado.', ['preset_id' => $preset->getKey(), 'active' => $preset->active]);

        return back();
    }
}
