<script setup lang="ts">
/**
 * A small, honest illustration of what a feature tile links to — drawn in
 * CSS, no data, no claims. Each one echoes a real screen (the roster, the
 * evolution line, the timetable, a report) so the bento tiles are not five
 * identical icon-title-text cards.
 */
defineProps<{ kind: 'roster' | 'trend' | 'timetable' | 'report' }>();

const roster = [
    { number: '03', pseudonym: 'ALU-B1HS' },
    { number: '07', pseudonym: 'ALU-LFJ0' },
    { number: '12', pseudonym: 'ALU-IAUH' },
] as const;

/** Evolution across periods, in percent — the shape, not a result. */
const trend = [52, 61, 58, 73] as const;

const timetable = [
    { day: 'Seg', slots: ['bg-blue-200', 'bg-blue-100', ''] },
    { day: 'Ter', slots: ['', 'bg-amber-200', 'bg-blue-100'] },
    { day: 'Qua', slots: ['bg-blue-100', '', 'bg-emerald-200'] },
    { day: 'Qui', slots: ['bg-amber-200', 'bg-blue-100', ''] },
    { day: 'Sex', slots: ['', 'bg-blue-200', ''] },
] as const;

const points = trend
    .map(
        (value, index) =>
            `${(index / (trend.length - 1)) * 100},${100 - value}`,
    )
    .join(' ');
</script>

<template>
    <div
        aria-hidden="true"
        class="mt-6 overflow-hidden rounded-xl bg-slate-50 p-3 ring-1 ring-black/5"
    >
        <ul v-if="kind === 'roster'" class="space-y-1.5">
            <li
                v-for="student in roster"
                :key="student.number"
                class="flex items-center gap-2 rounded-md bg-white px-2 py-1.5 text-[11px] text-slate-600"
            >
                <span class="w-4 text-slate-400 tabular-nums">{{
                    student.number
                }}</span>
                <span class="size-5 rounded-full bg-amber-100" />
                <span class="h-2 flex-1 rounded bg-slate-200" />
                <span class="font-mono text-[10px] text-slate-400">{{
                    student.pseudonym
                }}</span>
            </li>
        </ul>

        <svg
            v-else-if="kind === 'trend'"
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            class="h-20 w-full"
        >
            <polyline
                :points="points"
                fill="none"
                stroke="#059669"
                stroke-width="3"
                stroke-linejoin="round"
                stroke-linecap="round"
                vector-effect="non-scaling-stroke"
            />
            <circle
                v-for="(value, index) in trend"
                :key="index"
                :cx="(index / (trend.length - 1)) * 100"
                :cy="100 - value"
                r="2.5"
                fill="#059669"
                vector-effect="non-scaling-stroke"
            />
        </svg>

        <div v-else-if="kind === 'timetable'" class="grid grid-cols-5 gap-1.5">
            <div v-for="column in timetable" :key="column.day">
                <p
                    class="mb-1 text-center text-[9px] font-semibold text-slate-400 uppercase"
                >
                    {{ column.day }}
                </p>
                <div class="space-y-1">
                    <span
                        v-for="(slot, index) in column.slots"
                        :key="index"
                        class="block h-3 rounded"
                        :class="slot || 'bg-white'"
                    />
                </div>
            </div>
        </div>

        <div v-else class="space-y-2 rounded-md bg-white p-3">
            <span class="block h-2.5 w-2/5 rounded bg-slate-800" />
            <span class="block h-1.5 w-full rounded bg-slate-200" />
            <span class="block h-1.5 w-11/12 rounded bg-slate-200" />
            <span class="block h-1.5 w-3/5 rounded bg-slate-200" />
            <span class="mt-2 block h-2 w-1/4 rounded bg-blue-200" />
        </div>
    </div>
</template>
