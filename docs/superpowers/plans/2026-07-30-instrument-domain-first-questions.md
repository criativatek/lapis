# Questões Agrupadas por Domínio Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorganize the instrument create/edit form so a teacher first picks which domains the instrument covers, then adds questions inside each domain's own section — a question born inside a domain section is pre-allocated 100% to it automatically.

**Architecture:** Pure frontend reorganization. `form.items` (the existing reactive array, unchanged shape) stays the single source of truth; the domain sections are a computed VIEW over it (which items have an allocation to domain D), not a new data structure. A new `selectedDomainIds` ref drives which domain sections render. The existing per-question domain-allocation editor (add/remove a domain + its %) is extracted into a small reusable component so it isn't tripled across domain sections, the "sem domínio" section, and (potentially) three call sites — reused as-is, no logic change.

**Tech Stack:** Vue 3 + TypeScript, Inertia 3.

## Global Constraints

- **Sequencing and file-location check — read this before starting.** This plan modifies the instrument creation form. As of this plan's writing, that's the single file `resources/js/pages/instruments/Create.vue` — but plan `docs/superpowers/plans/2026-07-29-instrument-editing.md` (in progress) extracts its logic into `InstrumentForm.vue` (shared by a thinner `Create.vue` and a new `Edit.vue`), and plan `docs/superpowers/plans/2026-07-30-instrument-import-template.md` (queued, same dependency) adds an "Importar de outro instrumento" selector to the SAME file. Before starting Task 1: run `ls resources/js/pages/instruments/` and `git log --oneline` to determine (a) whether `InstrumentForm.vue` now exists (apply this plan to it, not to a since-thinned `Create.vue`), and (b) whether the import-template plan already landed its own changes to the same file (if so, this plan's changes must be added ALONGSIDE the import selector, not overwrite it — read the actual current file in full before editing either way, regardless of what this plan's steps show below against the ORIGINAL Create.vue).
- No backend, schema, route, or validation change of any kind — `InstrumentBuilder`, `InstrumentRequest`, and the controller stay untouched. This is entirely a client-side reorganization of the same `items` payload shape already sent today.
- A question may still count toward more than one domain — the existing per-question allocation editor (add another domain + %, remove one) keeps its exact current logic; only its position in the page changes (now inside whichever domain section the question already belongs to, via the SAME data).
- Unchecking a domain hides its section only — it never deletes or blocks an existing allocation to that domain.
- A question with zero domain allocations remains legitimate, shown in a permanent "Sem domínio associado" section.
- The "Soma das cotações" total keeps summing `form.items` in full, unaffected by how many domain sections exist.
- PT-pt copy throughout.

---

### Task 1: Extract the per-question allocation editor, then reorganize the page around domains

**Files:**
- Create: `resources/js/pages/instruments/InstrumentDomainAllocations.vue` (the extracted, reused sub-editor)
- Modify: `resources/js/pages/instruments/Create.vue` — **or wherever the create/edit form's reactive `items`/`domains` state actually lives; re-verify against the Global Constraints note above before touching anything.**

**Interfaces:**
- `InstrumentDomainAllocations.vue` props: `item: {domains: {domain_id: number; allocation_percent: number}[]}`, `domains: {id: number; label: string}[]`. No emits needed — it mutates `item.domains` directly (the same reactive array already owned by the parent's `form.items`), exactly as the current inline code already does via direct mutation (`item.domains.push(...)`/`.splice(...)`).

- [ ] **Step 1: Create the extracted allocation editor**

Create `resources/js/pages/instruments/InstrumentDomainAllocations.vue`:

```vue
<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Domain = { id: number; label: string };
type ItemDomains = { domains: { domain_id: number; allocation_percent: number }[] };

const props = defineProps<{
    item: ItemDomains;
    domains: Domain[];
}>();

function addAllocation(): void {
    props.item.domains.push({ domain_id: props.domains[0]?.id ?? 0, allocation_percent: 100 });
}

function removeAllocation(index: number): void {
    props.item.domains.splice(index, 1);
}

function allocationTotal(): number {
    return props.item.domains.reduce((sum, allocation) => sum + (Number(allocation.allocation_percent) || 0), 0);
}
</script>

<template>
    <div v-if="domains.length" class="space-y-2 border-t border-border pt-3">
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted-foreground">
                Domínios <template v-if="item.domains.length">— total {{ allocationTotal() }}%</template>
            </span>
            <Button type="button" variant="ghost" size="sm" @click="addAllocation">
                <Plus class="size-3.5" /> Domínio
            </Button>
        </div>
        <div v-for="(allocation, allocationIndex) in item.domains" :key="allocationIndex" class="flex items-center gap-2">
            <select v-model.number="allocation.domain_id" class="border-input h-8 flex-1 rounded-md border bg-transparent px-2 text-sm">
                <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.label }}</option>
            </select>
            <Input v-model.number="allocation.allocation_percent" type="number" min="0" max="100" class="h-8 w-20" />
            <span class="text-sm text-muted-foreground">%</span>
            <Button type="button" variant="ghost" size="icon" @click="removeAllocation(allocationIndex)">
                <Trash2 class="size-3.5" />
            </Button>
        </div>
        <p v-if="item.domains.length && Math.abs(allocationTotal() - 100) > 0.0001" class="text-xs text-amber-700">
            Tem de somar 100%.
        </p>
    </div>
    <p v-else class="text-xs text-muted-foreground">
        A turma não tem perfil ativo, por isso não há domínios para distribuir.
    </p>
</template>
```

This is a byte-for-byte behavioral copy of the current inline block (currently `Create.vue:176-198`, the `<div v-if="domains.length" class="space-y-2 border-t...">` through its matching closing `</div>`, plus the sibling `<p v-else>`) — same classes, same conditions, same text — just relocated so it can be reused per question regardless of which domain section that question is rendered inside of.

- [ ] **Step 2: Re-read the actual current form file, confirm its structure, then reorganize it**

Read the file identified by the Global Constraints check (Task 1's own first action) in full. It currently has (against the original `Create.vue`, for reference — re-verify line numbers against whatever you actually find):
- `ItemRow`/`Option` types, `props`, `form` (lines 11-52 in the original file).
- `addItem()`/`removeItem()` (lines 54-66) — unchanged, still needed for "Sem domínio associado".
- `addAllocation()`/`removeAllocation()`/`allocationTotal()` (lines 68-78) — **delete these three**, their logic now lives inside `InstrumentDomainAllocations.vue`.
- `itemsTotal`/`totalMatches` computed values (lines 80-86) — unchanged.
- The template's "Questões" section (lines 143-213 in the original file) — **replaced** per Step 3 below.

Add this new reactive state and these new functions (placed near `addItem`/`removeItem`):

```ts
import InstrumentDomainAllocations from './InstrumentDomainAllocations.vue';

const selectedDomainIds = ref<number[]>([]);

function itemsForDomain(domainId: number): { item: ItemRow; index: number }[] {
    return form.items
        .map((item, index) => ({ item, index }))
        .filter(({ item }) => item.domains.some((allocation) => allocation.domain_id === domainId));
}

function itemsWithNoDomain(): { item: ItemRow; index: number }[] {
    return form.items
        .map((item, index) => ({ item, index }))
        .filter(({ item }) => item.domains.length === 0);
}

function addItemToDomain(domainId: number): void {
    form.items.push({
        code: `Q${form.items.length + 1}`,
        label: '',
        points_possible: 0,
        is_bonus: false,
        domains: [{ domain_id: domainId, allocation_percent: 100 }],
    });
}
```

(`ref` needs importing from `'vue'` alongside the already-imported `computed` — check the current import line first, e.g. `import { computed, ref } from 'vue';`. `addItem()` stays exactly as it is today — it's what "Sem domínio associado"'s own add button will keep calling, since a domain-less question already gets `domains: []` from it unchanged.)

- [ ] **Step 3: Replace the template's "Questões" section**

Replace the whole current single "Questões" `<section>` (in the original file, lines 143-213 — from `<section class="space-y-3">` that contains `<h2 ...>Questões</h2>` through its matching `</section>`, which also contains the "Soma das cotações" summary div) with:

```html
<section class="space-y-3">
    <div>
        <h2 class="text-sm font-semibold">Domínios avaliados</h2>
        <p class="text-sm text-muted-foreground">Escolhe os domínios que este instrumento avalia — depois cria as questões dentro de cada um.</p>
    </div>
    <div v-if="domains.length" class="flex flex-wrap gap-4">
        <label v-for="domain in domains" :key="domain.id" class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="selectedDomainIds" :value="domain.id" class="size-4" />
            {{ domain.label }}
        </label>
    </div>
    <p v-else class="text-xs text-muted-foreground">
        A turma não tem perfil ativo, por isso não há domínios para escolher.
    </p>
</section>

<section v-for="domainId in selectedDomainIds" :key="domainId" class="space-y-3">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold">{{ domains.find((d) => d.id === domainId)?.label }}</h2>
        <Button type="button" variant="outline" size="sm" @click="addItemToDomain(domainId)">
            <Plus class="size-4" /> Adicionar questão
        </Button>
    </div>
    <div v-for="{ item, index } in itemsForDomain(domainId)" :key="index" class="space-y-3 rounded-lg border border-border p-3">
        <div class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]">
            <div class="grid gap-1.5">
                <Label class="text-xs">Código</Label>
                <Input v-model="item.code" placeholder="Q1" />
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Enunciado (opcional)</Label>
                <Input v-model="item.label" placeholder="Compreensão do texto" />
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Cotação</Label>
                <Input v-model.number="item.points_possible" type="number" min="0" step="0.25" />
            </div>
            <Button type="button" variant="ghost" size="icon" :disabled="form.items.length <= 1" @click="removeItem(index)">
                <Trash2 class="size-4" />
            </Button>
        </div>
        <InstrumentDomainAllocations :item="item" :domains="domains" />
    </div>
</section>

<section class="space-y-3">
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-semibold">Sem domínio associado</h2>
        <Button type="button" variant="outline" size="sm" @click="addItem">
            <Plus class="size-4" /> Adicionar questão
        </Button>
    </div>
    <InputError :message="form.errors.items" />
    <div v-for="{ item, index } in itemsWithNoDomain()" :key="index" class="space-y-3 rounded-lg border border-border p-3">
        <div class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]">
            <div class="grid gap-1.5">
                <Label class="text-xs">Código</Label>
                <Input v-model="item.code" placeholder="Q1" />
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Enunciado (opcional)</Label>
                <Input v-model="item.label" placeholder="Compreensão do texto" />
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Cotação</Label>
                <Input v-model.number="item.points_possible" type="number" min="0" step="0.25" />
            </div>
            <Button type="button" variant="ghost" size="icon" :disabled="form.items.length <= 1" @click="removeItem(index)">
                <Trash2 class="size-4" />
            </Button>
        </div>
        <InstrumentDomainAllocations :item="item" :domains="domains" />
    </div>
</section>

<div
    class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
    :class="totalMatches ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-amber-300 bg-amber-50 text-amber-900'"
>
    <span class="font-medium">Soma das cotações</span>
    <span class="font-semibold tabular-nums">
        {{ itemsTotal }}{{ form.total_points ? ` / ${form.total_points}` : '' }} {{ totalMatches ? '✓' : '' }}
    </span>
</div>
```

The per-domain section deliberately has no empty-state message of its own — `form.errors.items` (a whole-array validation error) is shown once, in the "Sem domínio associado" section below, which is sufficient.

The `<Button type="submit">Criar instrumento</Button>` at the end of the form (or "Guardar alterações" if this is now `InstrumentForm.vue` in edit mode) is unaffected — leave it exactly where it is, after all three new sections.

- [ ] **Step 4: Build and type-check**

Run: `npm run build`
Expected: builds cleanly.

Run: `npm run types:check`
Expected: clean.

- [ ] **Step 5: Manual verification**

1. On the local site, open "Novo instrumento" for a class with an active profile with at least 3 domains.
2. Confirm "Domínios avaliados" appears first, with a checkbox per domain, none checked initially, and no domain sections or "Sem domínio associado" questions shown yet other than the one default `Q1` row (created by the form's own initial `items` default) — since that default question has `domains: []`, confirm it appears under "Sem domínio associado", not lost.
3. Check 2 domains: confirm their two sections appear, each with its own "+ Adicionar questão".
4. Click "+ Adicionar questão" inside one domain's section: confirm the new question appears ONLY in that section, and inspecting its allocation editor shows it already 100% allocated to that domain.
5. In that question's own allocation editor, click "+ Domínio" and add a second domain (one of the other checked ones) at 20%, adjusting the first to 80%: confirm the SAME question now also appears in that second domain's section (not a duplicate row — the same question, visible from both sections).
6. Uncheck one of the two checked domains: confirm its section disappears, and any question that ALSO had an allocation to the other still-checked domain still appears there; a question that ONLY had an allocation to the domain you just unchecked (and no other) still exists in `form.items` (nothing was deleted) but is no longer visible from any domain section — only from "Sem domínio associado" if it now also has zero domains, or from nowhere in the domain sections if it still has a domain allocation to the hidden one (this is expected per the spec's rule 4 — describe exactly what you observe here, since this is the one deliberately "invisible but not lost" edge case).
7. Confirm "Soma das cotações" at the bottom still reflects ALL questions correctly regardless of grouping.
8. Submit the form: confirm the instrument is created with exactly the questions/allocations you set up, matching what the same payload would have produced under the old flat list (the network payload shape is unchanged — verify via the browser's network tab that the `items` array sent looks the same shape as before this change).

Describe what you actually observed for each of these 8 checks.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/instruments/InstrumentDomainAllocations.vue
git add resources/js/pages/instruments/Create.vue
git commit -m "feat(instruments): group questions by domain in the instrument form"
```

(Adjust the second `git add` path if this task ended up editing `InstrumentForm.vue` instead, per the sequencing note.)
