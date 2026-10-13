<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ClassPicker from '@/components/records/ClassPicker.vue';
import type { ConsultationFilters, RegisterContext } from '@/lib/recordFollowup';
import { formatDay, tabQuery, weekday } from '@/lib/recordFollowup';
import HomeworkGrid from '@/pages/records/HomeworkGrid.vue';

/**
 * «Registar TPC» — the class and the day, and straight away the grid with every
 * student of that day. No search first, no form per student.
 *
 * Changing the class or the day goes through the grid's guard: what is still
 * «por guardar» is never lost without the teacher deciding.
 */
type ClassOption = { ulid: string; label: string; subject: string; archived: boolean };

const props = defineProps<{
    register: RegisterContext;
    classes: ClassOption[];
    filters: ConsultationFilters;
    yearLabel: string | null;
}>();

const grid = ref<InstanceType<typeof HomeworkGrid> | null>(null);

const selectableClasses = computed(() => props.classes.filter((option) => !option.archived || option.ulid === props.register.class?.ulid));

function go(classUlid: string | null, date: string): void {
    const action = (): void => {
        router.get('/records', tabQuery({ ...props.filters, class: classUlid }, 'tpc', { date }), { preserveScroll: true });
    };

    if (grid.value) {
        grid.value.request(action);
    } else {
        action();
    }
}

function onClassChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    const previous = props.register.class?.ulid ?? '';

    // The select shows the class actually loaded until the teacher decides.
    (event.target as HTMLSelectElement).value = previous;
    go(value, props.register.date);
}

function onDateChange(event: Event): void {
    const value = (event.target as HTMLInputElement).value;

    (event.target as HTMLInputElement).value = props.register.date;

    if (value !== '') {
        go(props.register.class?.ulid ?? null, value);
    }
}
</script>

<template>
    <div class="space-y-4" data-test="register-tab">
        <ClassPicker v-if="register.class === null" :classes="classes" :include-archived="false" title="Escolha a turma para registar o TPC" @select="(ulid) => go(ulid, register.date)" />

        <template v-else>
            <div class="card-soft grid gap-3 rounded-2xl border border-border/70 bg-card p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Turma<template v-if="yearLabel"> · {{ yearLabel }}</template></span>
                        <select :value="register.class.ulid" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="register-class" @change="onClassChange">
                            <option v-for="option in selectableClasses" :key="option.ulid" :value="option.ulid">{{ option.label }} · {{ option.subject }}</option>
                        </select>
                    </label>
                    <label class="text-sm">
                        <span class="mb-1 block text-xs font-medium text-muted-foreground">Data do TPC</span>
                        <input :value="register.date" type="date" class="min-h-11 w-full rounded-md border border-input bg-background px-3 text-sm" data-test="register-date" @change="onDateChange" />
                    </label>
                </div>
                <button
                    v-if="register.date !== register.today"
                    type="button"
                    class="min-h-11 rounded-md border border-border px-3 text-sm hover:bg-muted/40"
                    data-test="register-today"
                    @click="go(register.class.ulid, register.today)"
                >
                    Hoje
                </button>
            </div>

            <h2 class="text-base font-semibold" data-test="register-heading">
                {{ register.class.label }} · {{ register.class.subject }} — {{ weekday(register.date) }}, {{ formatDay(register.date) }}
                <span v-if="register.date === register.today" class="text-sm font-normal text-muted-foreground">(hoje)</span>
            </h2>

            <HomeworkGrid ref="grid" :class-ulid="register.class.ulid" :occurred-at="register.date" :read-only="!register.can_write" />
        </template>
    </div>
</template>
