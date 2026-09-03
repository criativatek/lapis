<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
type ModuleOption={key:string;name:string};
type Preset={id:number;key:string;name:string;notes:string|null;durationDays:number|null;active:boolean;moduleKeys:string[]};
type Voucher={ulid:string;code:string;label:string;durationDays:number;validFrom:string|null;validUntil:string|null;maxRedemptions:number|null;redemptionsCount:number;disabledAt:string|null;modules:ModuleOption[]};
const props=defineProps<{modules:ModuleOption[];presets:Preset[];organizations:{id:number;ulid:string;name:string}[];vouchers:Voucher[]}>();
const form=useForm({label:'',code:'',preset_id:null as number|null,duration_days:30,module_keys:[] as string[],valid_from:'',valid_until:'',max_redemptions:null as number|null,restricted_organization_id:null as number|null,notes:''});
const presetForm=useForm({key:'',name:'',notes:'',default_duration_days:30 as number|null,module_keys:[] as string[]});
const activePresets=computed(()=>props.presets.filter((preset)=>preset.active));
watch(()=>form.preset_id,(id)=>{
const preset=props.presets.find((item)=>item.id===id);

if(preset){
form.module_keys=[...preset.moduleKeys];

if(preset.durationDays){
form.duration_days=preset.durationDays;
}
}
});
function submitVoucher():void{
form.post('/admin/capabilities/vouchers',{preserveScroll:true,onSuccess:()=>form.reset()});
}
function submitPreset():void{
presetForm.post('/admin/capabilities/presets',{preserveScroll:true,onSuccess:()=>presetForm.reset()});
}
function disable(voucher:Voucher):void{
if(confirm(`Desativar o código ${voucher.code}?`)){
router.post(`/admin/capabilities/vouchers/${voucher.ulid}/disable`,{}, {preserveScroll:true});
}
}
function togglePreset(preset:Preset):void{
router.post(`/admin/capabilities/presets/${preset.id}/toggle`,{}, {preserveScroll:true});
}
</script>
<template>
<Head title="Capacidades temporárias" />
<div class="space-y-8 p-6"><header><h1 class="text-xl font-semibold">Capacidades temporárias</h1><p class="text-sm text-muted-foreground">Acesso temporário sem alterar o plano da conta.</p></header>
<section class="grid gap-6 lg:grid-cols-2"><form class="space-y-4 rounded-lg border p-4" @submit.prevent="submitVoucher"><h2 class="font-medium">Gerar código</h2><input v-model="form.label" required placeholder="Etiqueta interna" class="w-full rounded-md border px-3 py-2" /><InputError :message="form.errors.label" /><select v-model="form.preset_id" class="w-full rounded-md border px-3 py-2"><option :value="null">Sem preset</option><option v-for="preset in activePresets" :key="preset.id" :value="preset.id">{{ preset.name }}</option></select><div class="grid max-h-64 gap-2 overflow-auto rounded-md border p-3"><label v-for="module in modules" :key="module.key" class="flex gap-2 text-sm"><input v-model="form.module_keys" type="checkbox" :value="module.key" />{{ module.name }} <span class="text-muted-foreground">({{ module.key }})</span></label></div><InputError :message="form.errors.module_keys" /><div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Duração (dias)<input v-model.number="form.duration_days" type="number" min="1" required class="mt-1 w-full rounded-md border px-3 py-2" /></label><label class="text-sm">Máximo de resgates<input v-model.number="form.max_redemptions" type="number" min="1" class="mt-1 w-full rounded-md border px-3 py-2" /></label></div><div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Disponível desde<input v-model="form.valid_from" type="datetime-local" class="mt-1 w-full rounded-md border px-3 py-2" /></label><label class="text-sm">Disponível até<input v-model="form.valid_until" type="datetime-local" class="mt-1 w-full rounded-md border px-3 py-2" /></label></div><select v-model="form.restricted_organization_id" class="w-full rounded-md border px-3 py-2"><option :value="null">Qualquer organização</option><option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option></select><Button :disabled="form.processing">Gerar código</Button></form>
<form class="space-y-4 rounded-lg border p-4" @submit.prevent="submitPreset"><h2 class="font-medium">Novo preset interno</h2><input v-model="presetForm.key" required placeholder="Chave" class="w-full rounded-md border px-3 py-2" /><input v-model="presetForm.name" required placeholder="Nome" class="w-full rounded-md border px-3 py-2" /><div class="grid max-h-64 gap-2 overflow-auto rounded-md border p-3"><label v-for="module in modules" :key="module.key" class="flex gap-2 text-sm"><input v-model="presetForm.module_keys" type="checkbox" :value="module.key" />{{ module.name }}</label></div><label class="text-sm">Duração sugerida<input v-model.number="presetForm.default_duration_days" type="number" min="1" class="mt-1 w-full rounded-md border px-3 py-2" /></label><Button :disabled="presetForm.processing">Guardar preset</Button><ul class="space-y-2"><li v-for="preset in presets" :key="preset.id" class="flex items-center justify-between text-sm"><span>{{ preset.name }} — {{ preset.active ? 'ativo' : 'inativo' }}</span><Button type="button" variant="outline" size="sm" @click="togglePreset(preset)">{{ preset.active ? 'Desativar' : 'Ativar' }}</Button></li></ul></form></section>
<section class="space-y-3"><h2 class="font-medium">Códigos emitidos</h2><div v-for="voucher in vouchers" :key="voucher.ulid" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"><div><p class="font-mono font-semibold">{{ voucher.code }}</p><p class="text-sm">{{ voucher.label }} · {{ voucher.durationDays }} dias · {{ voucher.redemptionsCount }} utilização(ões)</p><p class="text-xs text-muted-foreground">{{ voucher.modules.map((module)=>module.name).join(', ') }}</p></div><Button v-if="!voucher.disabledAt" variant="outline" @click="disable(voucher)">Desativar</Button><span v-else class="text-sm text-muted-foreground">Desativado</span></div></section></div>
</template>
