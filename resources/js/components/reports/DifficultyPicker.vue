<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type LibraryEntry = { code: string | null; label: string; objective: string | null };

export type ChosenStrategy = { code: string | null; label: string; objective: string | null };

export type ChosenDifficulty = {
    code: string | null;
    label: string;
    domain: string | null;
    note: string | null;
    strategies: ChosenStrategy[];
};

const props = defineProps<{
    modelValue: ChosenDifficulty[];
    difficulties: LibraryEntry[];
    strategies: Record<string, LibraryEntry[]>;
    domains: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [ChosenDifficulty[]] }>();

const picking = ref('');
const ownLabel = ref('');

const available = computed(() =>
    props.difficulties.filter((entry) => !props.modelValue.some((chosen) => chosen.code === entry.code)),
);

function update(next: ChosenDifficulty[]) {
    emit('update:modelValue', next);
}

function addFromLibrary() {
    const entry = props.difficulties.find((row) => row.code === picking.value);

    if (!entry) {
        return;
    }

    update([...props.modelValue, { code: entry.code, label: entry.label, domain: null, note: null, strategies: [] }]);
    picking.value = '';
}

function addOwn() {
    const label = ownLabel.value.trim();

    if (label === '') {
        return;
    }

    // A difficulty the teacher typed has no code and no library strategies —
    // they write their own, or none.
    update([...props.modelValue, { code: null, label, domain: null, note: null, strategies: [] }]);
    ownLabel.value = '';
}

function remove(index: number) {
    const next = [...props.modelValue];
    next.splice(index, 1);
    update(next);
}

function strategiesFor(difficulty: ChosenDifficulty): LibraryEntry[] {
    return difficulty.code === null ? [] : (props.strategies[difficulty.code] ?? []);
}

function isChosen(difficulty: ChosenDifficulty, entry: LibraryEntry): boolean {
    return difficulty.strategies.some((strategy) => strategy.code === entry.code);
}

function toggleStrategy(index: number, entry: LibraryEntry) {
    const next = [...props.modelValue];
    const difficulty = { ...next[index], strategies: [...next[index].strategies] };
    const position = difficulty.strategies.findIndex((strategy) => strategy.code === entry.code);

    if (position === -1) {
        difficulty.strategies.push({ code: entry.code, label: entry.label, objective: entry.objective });
    } else {
        difficulty.strategies.splice(position, 1);
    }

    next[index] = difficulty;
    update(next);
}

function setField(index: number, field: 'domain' | 'note', value: string) {
    const next = [...props.modelValue];
    next[index] = { ...next[index], [field]: value.trim() === '' ? null : value };
    update(next);
}
</script>

<!--
    Choosing difficulties, and the strategies that answer each one (§14).

    THE NESTING IS THE DESIGN. Strategies live inside the difficulty they
    answer, so a teacher cannot end up with a list of measures floating free of
    what they are for — which is exactly what makes a generated report read like
    a form letter. A difficulty typed by hand offers no library strategies,
    because none of them were written for it.
-->
<template>
    <div class="space-y-3">
        <ul v-if="modelValue.length > 0" class="space-y-3">
            <li
                v-for="(difficulty, index) in modelValue"
                :key="`${difficulty.code ?? 'livre'}-${index}`"
                data-test="difficulty-card"
                class="space-y-4 rounded-lg border border-border p-4"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="font-medium tabular-nums">{{ index + 1 }} · {{ difficulty.label }}</p>
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Remover esta dificuldade"
                        @click="remove(index)"
                    >
                        <X class="size-3.5" />
                    </Button>
                </div>

                <label class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <span>Domínio:</span>
                    <select
                        data-test="difficulty-domain"
                        class="h-7 rounded-md border border-border bg-background px-2 text-xs text-foreground"
                        :value="difficulty.domain ?? ''"
                        @change="setField(index, 'domain', ($event.target as HTMLSelectElement).value)"
                    >
                        <option value="">Nenhum</option>
                        <option v-for="domain in domains" :key="domain" :value="domain">{{ domain }}</option>
                    </select>
                </label>

                <div v-if="strategiesFor(difficulty).length > 0" class="space-y-1.5">
                    <p class="text-xs font-medium text-muted-foreground">Estratégias para esta dificuldade</p>
                    <label
                        v-for="entry in strategiesFor(difficulty)"
                        :key="entry.code ?? entry.label"
                        class="flex items-start gap-2 text-sm"
                    >
                        <input
                            type="checkbox"
                            class="mt-1 size-3.5 shrink-0"
                            :checked="isChosen(difficulty, entry)"
                            @change="toggleStrategy(index, entry)"
                        />
                        <span>
                            {{ entry.label }}
                            <span v-if="entry.objective" class="block text-xs text-muted-foreground">
                                Objetivo: {{ entry.objective }}
                            </span>
                        </span>
                    </label>
                </div>

                <p v-else-if="difficulty.code === null" class="text-xs text-muted-foreground">
                    Dificuldade escrita por si — as estratégias podem ser acrescentadas no texto da secção.
                </p>

                <label class="grid gap-1">
                    <span class="text-xs text-muted-foreground">Nota complementar</span>
                    <Input
                        data-test="difficulty-note"
                        class="h-8"
                        :model-value="difficulty.note ?? ''"
                        @update:model-value="setField(index, 'note', String($event))"
                    />
                </label>
            </li>
        </ul>

        <div
            data-test="difficulty-add-zone"
            class="space-y-3 rounded-lg border border-dashed border-border bg-muted/30 p-4"
        >
            <p class="flex items-center gap-2 text-sm font-medium">
                <Plus class="size-4" />
                Acrescentar outra dificuldade
            </p>

            <div class="flex flex-wrap items-end gap-2">
                <label class="grid flex-1 gap-1">
                    <span class="text-xs text-muted-foreground">Acrescentar da biblioteca</span>
                    <select
                        v-model="picking"
                        data-test="library-difficulty-select"
                        class="h-9 rounded-md border border-border bg-background px-2 text-sm"
                    >
                        <option value="">Escolher…</option>
                        <option v-for="entry in available" :key="entry.code ?? entry.label" :value="entry.code ?? ''">
                            {{ entry.label }}
                        </option>
                    </select>
                </label>
                <Button
                    data-test="add-library-difficulty"
                    variant="outline"
                    size="sm"
                    :disabled="picking === ''"
                    @click="addFromLibrary"
                >
                    <Plus class="size-3.5" />
                    Acrescentar
                </Button>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <label class="grid flex-1 gap-1">
                    <span class="text-xs text-muted-foreground">Ou escrever a sua</span>
                    <Input
                        v-model="ownLabel"
                        data-test="own-difficulty-input"
                        class="h-9"
                        placeholder="Ex.: Leitura em voz alta"
                    />
                </label>
                <Button
                    data-test="add-own-difficulty"
                    variant="outline"
                    size="sm"
                    :disabled="ownLabel.trim() === ''"
                    @click="addOwn"
                >
                    <Plus class="size-3.5" />
                    Acrescentar
                </Button>
            </div>
        </div>
    </div>
</template>
