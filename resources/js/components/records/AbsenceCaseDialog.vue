<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed } from 'vue';
import { Dialog, DialogDescription, DialogHeader, DialogScrollContent, DialogTitle } from '@/components/ui/dialog';
import { formatLessonNumber } from '@/lib/lessons';
import type { AbsenceDetail } from '@/lib/recordFollowup';
import { absencesLabel, daysLabel, formatDay, studentLabel, weekday } from '@/lib/recordFollowup';

/**
 * One student's «Faltas de presença» in one class: each absence is a lesson
 * where the registered attendance says Falta, with a way to open that lesson.
 * Consultation only — no alert, no Inovar message, nothing to mark.
 */
const props = defineProps<{
    detail: AbsenceDetail;
    /** The consultation URL as it stands — where the lesson's «Voltar» comes back to. */
    currentUrl: string;
}>();

const emit = defineEmits<{ close: [] }>();

/** The lesson page reads this to bring the teacher back here (lessons/Show.vue). */
const RETURN_KEY = 'lapis.lessons.return';

function rememberReturn(lessonUlid: string): void {
    try {
        window.sessionStorage.setItem(RETURN_KEY, JSON.stringify({ url: props.currentUrl, lesson: lessonUlid }));
    } catch {
        // Sem sessionStorage, o «Voltar» da aula vai para a semana da aula.
    }
}

const figures = computed(() => `${absencesLabel(props.detail.count)} em ${daysLabel(props.detail.days)}`);

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogScrollContent class="max-w-2xl gap-5 p-4 sm:p-6" data-test="absence-case">
            <DialogHeader class="pr-8 text-left">
                <DialogTitle class="text-lg">{{ studentLabel(detail.student.number, detail.student.name) }}</DialogTitle>
                <DialogDescription>
                    {{ detail.class.label }} · {{ detail.class.subject }} — {{ detail.category_label }} — {{ detail.window.label }}
                </DialogDescription>
            </DialogHeader>

            <p class="text-sm font-semibold tabular-nums" data-test="absence-figures">{{ figures }}</p>

            <section aria-labelledby="absence-list">
                <h3 id="absence-list" class="mb-2 text-sm font-semibold">Aulas com falta</h3>
                <p v-if="detail.count === 0" class="rounded-lg border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">
                    Já não há faltas registadas neste período (foram corrigidas ou a aula deixou de contar).
                </p>
                <ol v-else class="divide-y divide-border rounded-lg border border-border">
                    <li v-for="absence in detail.absences" :key="absence.ulid" class="flex items-center gap-3 px-3 py-2">
                        <p class="min-w-0 flex-1 text-sm">
                            <span class="font-medium tabular-nums">{{ formatDay(absence.date) }}</span>
                            <span class="text-muted-foreground"> · {{ weekday(absence.date) }} · {{ absence.time }}</span>
                            <span class="text-muted-foreground"> · {{ absence.context_label }}</span>
                            <span v-if="absence.lesson_number !== null" class="text-muted-foreground"> · lição n.º {{ formatLessonNumber(absence.lesson_number, absence.lesson_number_end) }}</span>
                        </p>
                        <Link
                            :href="`/lessons/${absence.lesson_ulid}`"
                            class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-md px-2 text-xs font-medium text-primary hover:bg-muted/40"
                            :aria-label="`Abrir a aula de ${formatDay(absence.date)} às ${absence.time}`"
                            @click="rememberReturn(absence.lesson_ulid)"
                        >
                            <ExternalLink class="size-3.5" aria-hidden="true" /> Abrir aula
                        </Link>
                    </li>
                </ol>
            </section>

            <section aria-labelledby="absence-rules" class="space-y-1 border-t border-border pt-4 text-sm text-muted-foreground">
                <h3 id="absence-rules" class="text-sm font-semibold text-foreground">Como se contam</h3>
                <p>Cada falta é uma aula (tempo) em que o aluno ficou com Falta na assiduidade registada. Duas aulas no mesmo dia são duas faltas num só dia.</p>
                <p>Só conta a assiduidade registada ao marcar a aula como lecionada. Um rascunho de faltas, uma aula sem assiduidade aplicável e um atraso não contam.</p>
                <p v-if="detail.not_recorded > 0" data-test="not-recorded">
                    {{ detail.not_recorded === 1 ? 'Há 1 aula lecionada' : `Há ${detail.not_recorded} aulas lecionadas` }} neste período com a assiduidade por registar — não contam como faltas.
                </p>
                <p>Nesta versão a assiduidade não distingue faltas justificadas de injustificadas.</p>
            </section>
        </DialogScrollContent>
    </Dialog>
</template>
