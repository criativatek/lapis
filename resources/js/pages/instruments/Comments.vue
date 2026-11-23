<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { statusToneClasses } from '@/lib/statusTone';
import type { ResultsNote } from '@/types/resultsAnalysis';
import InstrumentTabs from './InstrumentTabs.vue';

type CommentItem = {
    id: number;
    code: string;
    label: string | null;
    points_possible: number | null;
    is_bonus: boolean;
    criterion_note: string | null;
};

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        status: string;
        status_label: string;
        applied_on: string;
        class_label: string;
        class_ulid: string;
        period: string;
    };
    note: ResultsNote;
    can_edit: boolean;
    items: CommentItem[];
    links: { note: string; item_notes: string; grid: string; results: string };
    cancelled: boolean;
}>();

const appliedOnLabel = computed(() =>
    new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' }).format(
        new Date(`${props.instrument.applied_on}T00:00:00Z`),
    ),
);

// ------------------------------------------------------------- Geral

const noteForm = useForm<{ body: string; lock_version: number }>({
    body: props.note.body,
    lock_version: props.note.lock_version,
});

function submitNote(): void {
    noteForm.put(props.links.note, {
        preserveScroll: true,
        // O servidor incrementa a versão a cada gravação. Sem a levar para o
        // formulário, a gravação seguinte NESTE separador seguia com a antiga
        // e era recusada como «alteradas noutra janela». Só aqui, no sucesso
        // da própria gravação: copiar a versão sempre que as props mudam
        // apagaria a deteção de um conflito verdadeiro com outra janela.
        onSuccess: () => {
            noteForm.lock_version = props.note.lock_version;
        },
    });
}

const noteUpdatedLabel = computed(() => {
    if (props.note.updated_at === null) {
        return null;
    }

    const formatted = new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(props.note.updated_at));

    return props.note.updated_by ? `${formatted} · ${props.note.updated_by}` : formatted;
});

// ------------------------------------------------------------- Por questão / critério

const canEditItems = computed(() => props.can_edit && !props.cancelled);

const drafts = reactive<Record<number, string>>(Object.fromEntries(props.items.map((item) => [item.id, item.criterion_note ?? ''])));
const saved = reactive<Record<number, string>>({ ...drafts });
const busy = reactive<Record<number, boolean>>({});
const errors = reactive<Record<number, string | null>>({});

function itemChanged(item: CommentItem): boolean {
    return (drafts[item.id] ?? '').trim() !== (saved[item.id] ?? '');
}

function saveItem(item: CommentItem): void {
    if (busy[item.id] || !itemChanged(item)) {
        return;
    }

    const value = (drafts[item.id] ?? '').trim();

    busy[item.id] = true;
    errors[item.id] = null;

    router.put(props.links.item_notes, { notes: { [item.id]: value } }, {
        preserveScroll: true,
        preserveState: true,
        only: [],
        onSuccess: () => {
            saved[item.id] = value;
            drafts[item.id] = value;
        },
        onError: (received) => {
            errors[item.id] = Object.values(received)[0] ?? 'Não foi possível guardar o comentário.';
        },
        onFinish: () => {
            busy[item.id] = false;
        },
    });
}

function formatPoints(points: number | null): string {
    return points === null ? '' : `${String(points).replace('.', ',')} pts`;
}
</script>

<template>
    <Head :title="`Comentários — ${instrument.title}`" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-start gap-3">
                    <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${appliedOnLabel}`" />
                    <Badge class="mt-1" variant="secondary" :class="statusToneClasses(instrument.status)">
                        {{ instrument.status_label }}
                    </Badge>
                </div>
                <Link :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
        </div>

        <InstrumentTabs :ulid="instrument.ulid" active="comments" :cancelled="cancelled" />

        <p class="text-xs text-muted-foreground">
            Os comentários são privados: só o professor os vê. As observações são texto livre e podem conter
            informação identificável — reveja-as antes de partilhar um relatório.
        </p>

        <!-- Geral: a observação do professor sobre o elemento, sempre visível, mesmo sem resultados oficiais -->
        <section aria-labelledby="section-comments-general" class="space-y-2 rounded-lg border border-border bg-muted/10 p-4">
            <h2 id="section-comments-general" class="text-sm font-semibold">Geral</h2>
            <Textarea
                v-model="noteForm.body"
                :disabled="!can_edit"
                :readonly="!can_edit"
                rows="5"
                aria-label="Observações do professor"
                placeholder="Escreva aqui as suas observações sobre este instrumento."
            />
            <InputError :message="noteForm.errors.body" />
            <p class="text-xs text-muted-foreground">
                As observações ficam guardadas à parte e não se perdem quando os indicadores são recalculados.
            </p>
            <p v-if="noteUpdatedLabel" class="text-xs text-muted-foreground">Última alteração: {{ noteUpdatedLabel }}</p>
            <Button v-if="can_edit" type="button" size="sm" :disabled="noteForm.processing" @click="submitNote">
                Guardar observações
            </Button>
        </section>

        <!-- Por questão / critério: sobre a pergunta, nunca sobre um aluno -->
        <section aria-labelledby="section-comments-items" class="space-y-3 rounded-lg border border-border bg-muted/10 p-4">
            <div>
                <h2 id="section-comments-items" class="text-sm font-semibold">Por questão / critério</h2>
                <p class="text-xs text-muted-foreground">
                    Como se pontua cada questão, o que exige cada nível. Também aparecem na grelha de correção, ao passar o rato no código.
                </p>
            </div>
            <p v-if="items.length === 0" class="text-sm text-muted-foreground">Este elemento ainda não tem questões.</p>
            <ul v-else class="space-y-4">
                <li v-for="item in items" :key="item.id" class="space-y-1.5" :data-testid="`comment-item-${item.id}`">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-sm font-medium">{{ item.code }}</span>
                        <span v-if="item.label && item.label !== item.code" class="text-sm text-muted-foreground">{{ item.label }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ formatPoints(item.points_possible) }}<template v-if="item.is_bonus"> · bónus</template>
                        </span>
                    </div>
                    <Textarea
                        v-model="drafts[item.id]"
                        :disabled="!canEditItems"
                        :readonly="!canEditItems"
                        rows="2"
                        maxlength="2000"
                        :aria-label="`Comentário de ${item.code}`"
                        :placeholder="canEditItems ? 'Comentário sobre esta questão…' : ''"
                    />
                    <InputError :message="errors[item.id] ?? undefined" />
                    <Button
                        v-if="canEditItems"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="busy[item.id] || !itemChanged(item)"
                        :aria-label="`Guardar comentário de ${item.code}`"
                        @click="saveItem(item)"
                    >
                        Guardar
                    </Button>
                </li>
            </ul>
        </section>

        <!-- Por aluno: lugar previsto para a fatia seguinte (comentários por aluno, PDF e cronologia do aluno).
             Fica de fora de propósito — nada se mostra nem se grava aqui por agora. -->
    </div>
</template>
