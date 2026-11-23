<script setup lang="ts">
import { MessageSquare } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import StudentCommentDialog from './StudentCommentDialog.vue';

/**
 * O indicador do comentário privado sobre um aluno, junto ao nome na grelha.
 *  - com comentário: sempre visível e destacado; o texto aparece ao passar o
 *    rato e ao receber foco (tooltip);
 *  - sem comentário e com edição: só aparece ao passar o rato na linha ou ao
 *    ter foco lá dentro (o `group` está na linha), para não atulhar a grelha;
 *  - sem comentário e sem edição: nada.
 */
const props = defineProps<{
    url: string;
    studentName: string;
    comment: { body: string; lock_version: number } | null;
    editable: boolean;
}>();

const current = ref<{ body: string; lock_version: number } | null>(props.comment);
const lockVersion = ref(props.comment?.lock_version ?? 0);
const open = ref(false);

watch(() => props.comment, (value) => {
    current.value = value;
    lockVersion.value = value?.lock_version ?? 0;
});

function onSaved(value: { body: string | null; lock_version: number }): void {
    lockVersion.value = value.lock_version;
    current.value = value.body === null ? null : { body: value.body, lock_version: value.lock_version };
}
</script>

<template>
    <TooltipProvider v-if="editable || current" :delay-duration="150">
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    :class="current
                        ? 'text-primary'
                        : 'text-muted-foreground opacity-0 group-focus-within/row:opacity-100 group-hover/row:opacity-100 focus-visible:opacity-100'"
                    :aria-label="current ? `${studentName} — com comentário` : `${studentName} — adicionar comentário`"
                    data-testid="student-comment-trigger"
                    @click="editable ? (open = true) : undefined"
                >
                    <MessageSquare class="size-3.5" :class="current ? 'fill-current' : ''" aria-hidden="true" />
                </button>
            </TooltipTrigger>
            <TooltipContent v-if="current && !open" class="max-w-xs text-left whitespace-pre-line" data-testid="student-comment-tooltip">
                {{ current.body }}
            </TooltipContent>
        </Tooltip>
    </TooltipProvider>

    <StudentCommentDialog
        v-if="editable"
        v-model:open="open"
        :url="url"
        :student-name="studentName"
        :body="current?.body ?? null"
        :lock-version="lockVersion"
        @saved="onSaved"
    />
</template>
