<script setup lang="ts">
import { Sparkles } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';

export type WritingModeOption = { value: string; label: string; description: string };

/**
 * «Aperfeiçoar redação» — the trigger, and only the trigger (§4, §18).
 *
 * IT IS NOT CALLED «GERAR COM IA», and the difference is not cosmetic. A button
 * that offers to generate would be telling a teacher that the machine is the
 * author of what follows, and in this application it never is: the sentences
 * were written from verified data before anybody clicked anything, and all this
 * can do is say them better.
 *
 * THE BUTTON IS ABSENT RATHER THAN BROKEN. A school whose plan does not include
 * it, and an installation with no engine configured, are two different states
 * with two different explanations, and neither of them is a control that fails
 * when pressed (§41). The reason is shown as a sentence where the button would
 * have been.
 *
 * THE NOTICE ABOUT NAMES IS PART OF THE MENU, not a line in a privacy page
 * nobody opens. On the sections that can carry a student's name, the teacher
 * reads what is about to happen to those names immediately above the modes —
 * which is what makes choosing a mode a decision rather than a default (§10).
 */
defineProps<{
    canRewrite: boolean;
    mayNameStudents: boolean;
    available: boolean;
    reason: string | null;
    modes: WritingModeOption[];
    busy: boolean;
}>();

const emit = defineEmits<{ request: [mode: string] }>();

const unavailableText: Record<string, string> = {
    plan: 'O apoio à redação faz parte dos planos Pro e Institucional.',
    provider: 'O apoio à redação não está configurado nesta instalação.',
};
</script>

<template>
    <span v-if="!canRewrite" />

    <span
        v-else-if="!available"
        class="text-xs text-muted-foreground"
        :title="unavailableText[reason ?? ''] ?? undefined"
    >
        {{ unavailableText[reason ?? ''] ?? '' }}
    </span>

    <DropdownMenu v-else>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="sm" :disabled="busy">
                <Spinner v-if="busy" class="size-3.5" />
                <Sparkles v-else class="size-3.5" />
                Aperfeiçoar redação
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-80">
            <DropdownMenuLabel>Aperfeiçoar como</DropdownMenuLabel>

            <p class="px-2 pb-1 text-xs text-muted-foreground">
                O texto é reescrito. Os números, as datas e as classificações não são enviados — vão substituídos
                por marcadores e voltam iguais.
            </p>

            <p v-if="mayNameStudents" class="px-2 pb-2 text-xs text-amber-700 dark:text-amber-500">
                Esta secção pode conter nomes de alunos. Os nomes da turma são substituídos por «Aluno A»,
                «Aluno B» antes do envio e repostos na sugestão.
            </p>

            <DropdownMenuSeparator />

            <DropdownMenuItem
                v-for="mode in modes"
                :key="mode.value"
                class="flex-col items-start gap-0.5"
                @select="emit('request', mode.value)"
            >
                <span class="font-medium">{{ mode.label }}</span>
                <span class="text-xs text-muted-foreground">{{ mode.description }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
