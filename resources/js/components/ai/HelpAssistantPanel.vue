<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BookOpen, RotateCcw, Sparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AiDisclosure from '@/components/ai/AiDisclosure.vue';
import AiTextPrivacyNotice from '@/components/ai/AiTextPrivacyNotice.vue';
import { useAiTextPrivacyGuard } from '@/composables/useAiTextPrivacyGuard';
import { assistant } from '@/routes/help';

/**
 * «Assistente Lapispro» — the Centro de Ajuda answering a written question.
 *
 * PART OF THE HELP PAGE, NOT A FLOATING CHATBOT. It sits under the search
 * box, in the same card language as the rest of the application, and appears
 * nowhere else in Lapispro. A bubble in the corner of every screen would be a
 * different product with a different promise: this one answers questions
 * about the documentation it is sitting in.
 *
 * NO STUDENT DATA REACHES IT, and the placeholder says so where a teacher
 * will actually read it. The endpoint accepts one field — the question — and
 * the assistant's only other input is the article set (see
 * `App\Services\Help\Ai\HelpAssistant`). There is no class, no period and no
 * result on this page to send even by accident.
 *
 * EVERY STATE IS A STATE, not an absence. Plan-locked, engine-unconfigured,
 * loading, answered, undocumented, and failed all render something a teacher
 * can act on — and the form survives all of them, so «tentar novamente» is
 * always one click and never a page reload.
 */

type Reference = { id: string; title: string; url: string };

type Answer = {
    question: string;
    text: string;
    references: Reference[];
    sufficient: boolean;
};

const props = withDefaults(
    defineProps<{
        ai: { available: boolean; reason: string | null };
        answer?: Answer | null;
        error?: { message: string } | null;
        /** Carried over from the search box, so a question typed there is not retyped here. */
        initialQuestion?: string;
    }>(),
    { answer: null, error: null, initialQuestion: '' },
);

const question = ref(props.initialQuestion);
const asking = ref(false);

// The server is the source of truth for both; a new round trip replaces what
// is on screen rather than appending to it. There is no conversation here —
// one question, one answer, and the next question starts over.
const answer = ref<Answer | null>(props.answer);
const error = ref<{ message: string } | null>(props.error);

watch(() => props.answer, (value) => {
    answer.value = value ?? null;
});
watch(() => props.error, (value) => {
    error.value = value ?? null;
});

/**
 * The gateway's reason slug, as a sentence.
 *
 * THREE OUTCOMES FROM SEVEN SLUGS, matching
 * `HelpAssistantController::unavailableMessage()` exactly. The Core
 * distinguishes `off` from `credential_missing`, `model_missing`,
 * `endpoint_missing`, `unknown_driver` and `fake_in_production`; a teacher
 * needs to know which of three people can help, not which setting is blank.
 * An unrecognised slug falls to «não configurado», which stays true for any
 * state the Core adds later.
 */
const unavailableMessage = computed(() => {
    if (props.ai.reason === 'plan') {
        return 'O Assistente Lapispro não está incluído no plano desta organização. Os artigos do Centro de Ajuda continuam disponíveis.';
    }

    if (props.ai.reason === 'off') {
        return 'O Assistente Lapispro não está ativado nesta instalação. Os artigos do Centro de Ajuda continuam disponíveis.';
    }

    return 'O Assistente Lapispro não está configurado nesta instalação. Os artigos do Centro de Ajuda continuam disponíveis.';
});

const canAsk = computed(() => props.ai.available && !asking.value && question.value.trim() !== '');

/** The answer as paragraphs. Plain text into plain elements — never `v-html`. */
const paragraphs = computed(() =>
    (answer.value?.text ?? '').split(/\n+/).map((line) => line.trim()).filter((line) => line !== ''),
);

/**
 * THE GUARD IS GIVEN NO NAMES, AND THAT IS THE RULE RATHER THAN AN OVERSIGHT.
 * The Centro de Ajuda holds no roster and must not fetch one — loading student
 * data in order to check whether a question mentions a student would be a worse
 * trade than the one it solves. So an arbitrary name goes undetected here; the
 * standing notice asks for it not to be written, and the Centro de Ajuda's own
 * article says the same in a teacher's words.
 */
const privacy = useAiTextPrivacyGuard();

function ask(): void {
    if (!canAsk.value) {
        return;
    }

    privacy.run(question.value, send);
}

function send(): void {
    // `question` is the ONLY thing posted. Nothing on this page could add a
    // student to it, and nothing here tries.
    router.post(
        assistant().url,
        { question: question.value.trim() },
        {
            preserveScroll: true,
            onStart: () => {
                asking.value = true;
                // Cleared on the way out rather than on the way in: the old
                // answer disappearing the instant a new question is asked is
                // what makes the spinner legible as «this one is being
                // answered», not «that one is still there».
                answer.value = null;
                error.value = null;
            },
            onFinish: () => {
                asking.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        aria-labelledby="assistente-lapispro"
        class="rounded-lg border border-border p-4"
    >
        <h2 id="assistente-lapispro" class="flex items-center gap-2 text-sm font-semibold">
            <Sparkles aria-hidden="true" class="size-4" />
            Assistente Lapispro
        </h2>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Faça uma pergunta sobre como utilizar o Lapispro. As respostas são construídas a partir dos artigos deste
            Centro de Ajuda — e só deles.
        </p>

        <p
            v-if="!ai.available"
            class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground"
        >
            {{ unavailableMessage }}
        </p>

        <form v-else class="mt-3 space-y-2" @submit.prevent="ask">
            <label class="block text-xs" for="assistente-pergunta">
                <span class="mb-1 block text-muted-foreground">A sua pergunta</span>
                <textarea
                    id="assistente-pergunta"
                    v-model="question"
                    rows="2"
                    maxlength="500"
                    :disabled="asking"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm disabled:opacity-60"
                    placeholder="Ex.: Como crio uma turma? Não inclua nomes de alunos nem dados pessoais."
                ></textarea>
            </label>

            <AiTextPrivacyNotice
                :findings="privacy.findings.value"
                notice="Não introduza nomes, contactos ou outros dados pessoais dos alunos. Não são precisos para responder."
                action-label="Perguntar mesmo assim"
                @edit="privacy.edit()"
                @proceed="privacy.proceed()"
            />

            <button
                v-if="!privacy.awaitingConfirmation.value"
                type="submit"
                :disabled="!canAsk"
                class="inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
            >
                <Sparkles aria-hidden="true" class="size-3.5" />
                {{ asking ? 'A procurar na documentação…' : 'Perguntar' }}
            </button>
        </form>

        <!-- One live region for everything that arrives after a click, so a
             screen reader is told the answer landed without the whole card
             being re-announced. -->
        <div aria-live="polite" :aria-busy="asking">
            <p v-if="asking" class="mt-3 text-xs text-muted-foreground">
                A procurar nos artigos do Centro de Ajuda…
            </p>

            <div
                v-else-if="error"
                class="mt-3 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-400"
            >
                <p>{{ error.message }}</p>
                <button
                    type="button"
                    class="mt-2 inline-flex items-center gap-1.5 font-medium underline underline-offset-2"
                    @click="ask"
                >
                    <RotateCcw aria-hidden="true" class="size-3" />
                    Tentar novamente
                </button>
            </div>

            <div v-else-if="answer" class="mt-4 border-t border-border/60 pt-4">
                <p class="text-xs text-muted-foreground">
                    Pergunta: <span class="font-medium text-foreground">{{ answer.question }}</span>
                </p>

                <!-- The documented-insufficiency case. Calm, not an error:
                     nothing failed, the documentation simply does not cover
                     this — and saying so is the correct answer. -->
                <p
                    v-if="!answer.sufficient"
                    class="mt-2 rounded-lg bg-muted/40 p-3 text-sm text-muted-foreground"
                >
                    {{ answer.text }}
                </p>

                <div v-else class="mt-2 space-y-2 text-sm">
                    <p v-for="(paragraph, index) in paragraphs" :key="index">{{ paragraph }}</p>
                </div>

                <div v-if="answer.references.length > 0" class="mt-3">
                    <h3 class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                        <BookOpen aria-hidden="true" class="size-3.5" />
                        Artigos em que esta resposta se baseia
                    </h3>
                    <ul class="mt-1.5 space-y-1">
                        <li v-for="reference in answer.references" :key="reference.id">
                            <a
                                :href="reference.url"
                                class="text-sm text-primary underline-offset-2 hover:underline"
                            >
                                {{ reference.title }}
                            </a>
                        </li>
                    </ul>
                </div>

                <AiDisclosure />
            </div>
        </div>
    </section>
</template>
