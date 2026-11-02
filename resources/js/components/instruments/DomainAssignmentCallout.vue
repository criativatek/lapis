<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * A grid whose questions have no domain cannot produce a result: the engine
 * only reads a question through its domain, so every row says «Por
 * classificar» with every mark filled in. This says so where the teacher is
 * looking, and offers the one step that fixes it — one domain per section,
 * confirmed once — without asking for a single mark again.
 */
export type DomainAssignment = {
    needed: boolean;
    can_assign: boolean;
    items_without_domain: number;
    groups: {
        ulid: string;
        label: string | null;
        items: number;
        items_without_domain: number;
        suggested_domain_id: number | null;
    }[];
    domains: { id: number; name: string }[];
};

const props = defineProps<{
    /** Where the assignment is posted: `/instruments/{ulid}/domains`. */
    postUrl: string;
    assignment: DomainAssignment;
    canEdit: boolean;
}>();

const open = ref(false);

const pendingGroups = computed(() =>
    props.assignment.groups.filter((group) => group.items_without_domain > 0),
);

/** Section ulid → chosen domain id. Pre-filled with the suggestion; the teacher confirms. */
const chosen = ref<Record<string, number | null>>({});

function openDialog(): void {
    chosen.value = Object.fromEntries(
        pendingGroups.value.map((group) => [group.ulid, group.suggested_domain_id]),
    );
    open.value = true;
}

const form = useForm({ assignments: [] as { group: string; domain_id: number }[] });

const complete = computed(() =>
    pendingGroups.value.every((group) => chosen.value[group.ulid] !== null && chosen.value[group.ulid] !== undefined),
);

function groupName(group: DomainAssignment['groups'][number], index: number): string {
    return group.label?.trim() || `Grupo ${index + 1}`;
}

function submit(): void {
    form.assignments = pendingGroups.value.map((group) => ({
        group: group.ulid,
        domain_id: chosen.value[group.ulid] as number,
    }));
    form.post(props.postUrl, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <div
        v-if="assignment.needed"
        class="flex flex-wrap items-start justify-between gap-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-100"
        role="status"
        data-testid="domain-assignment-callout"
    >
        <div class="flex items-start gap-2">
            <CircleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div>
                <p class="font-medium">
                    {{
                        assignment.items_without_domain === 1
                            ? '1 pergunta desta grelha não tem domínio atribuído.'
                            : `${assignment.items_without_domain} perguntas desta grelha não têm domínio atribuído.`
                    }}
                </p>
                <p class="mt-1">
                    Sem domínio, as classificações não entram no cálculo: não há classificação
                    oficial, resultados por domínio nem acompanhamento da turma.
                    <template v-if="assignment.can_assign">
                        Associe cada grupo a um domínio do perfil da turma — uma vez, sem voltar a
                        introduzir classificações.
                    </template>
                    <template v-else>
                        Esta turma não tem um perfil de avaliação ativo; defina-o primeiro.
                    </template>
                </p>
            </div>
        </div>
        <Button
            v-if="assignment.can_assign && canEdit"
            type="button"
            size="sm"
            data-testid="domain-assignment-open"
            @click="openDialog"
        >
            Associar domínios
        </Button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Associar cada grupo a um domínio</DialogTitle>
                    <DialogDescription>
                        Só as perguntas ainda sem domínio são associadas, a 100 % no domínio escolhido.
                        As classificações, as cotações e as associações já existentes não mudam.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3">
                    <div
                        v-for="(group, index) in pendingGroups"
                        :key="group.ulid"
                        class="grid grid-cols-1 gap-1 sm:grid-cols-2 sm:items-center sm:gap-3"
                    >
                        <label :for="`domain-for-${group.ulid}`" class="text-sm">
                            <span class="font-medium">{{ groupName(group, index) }}</span>
                            <span class="block text-xs text-muted-foreground">
                                {{ group.items_without_domain }} de {{ group.items }} pergunta{{ group.items === 1 ? '' : 's' }} sem domínio
                            </span>
                        </label>
                        <select
                            :id="`domain-for-${group.ulid}`"
                            v-model="chosen[group.ulid]"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                        >
                            <option :value="null">— Escolher domínio —</option>
                            <option v-for="domain in assignment.domains" :key="domain.id" :value="domain.id">
                                {{ domain.name }}
                            </option>
                        </select>
                    </div>
                    <InputError :message="form.errors.assignments" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancelar</Button>
                    <Button
                        type="button"
                        :disabled="!complete || form.processing"
                        data-testid="domain-assignment-submit"
                        @click="submit"
                    >
                        Associar e recalcular
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
