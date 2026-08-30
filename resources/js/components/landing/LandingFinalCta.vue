<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

/**
 * The last thing on the page, and the one place the whole argument is stated
 * as a position rather than as a feature.
 *
 * «O professor decide. O Lapispro simplifica o caminho.» is the pillar sentence
 * of the product, and it is deliberately said HERE and nowhere else on the
 * page: repeated in three sections it would read as a slogan, said once at
 * the end it reads as a commitment.
 *
 * A rounded navy card inside the measure with a photograph beside the words
 * when the page passes one. The photograph should show people — the CTA sells
 * the human result, not the paperwork. No face: see PageHero.
 */

withDefaults(
    defineProps<{
        authenticated: boolean;
        photo?: { src: string; alt: string } | null;
    }>(),
    { photo: null },
);

const chips = [
    'Sem compromisso',
    'Sem cartão',
    'Comece pelo Plano Base',
] as const;
</script>

<template>
    <section class="px-4 py-10 sm:px-6 sm:py-14">
        <div
            class="mx-auto grid w-full max-w-6xl items-center gap-10 overflow-hidden rounded-[2rem] bg-gradient-to-br from-[#1E4AB0] via-[#183B8F] to-[#102A56] px-6 py-12 text-white sm:rounded-[2.5rem] sm:px-10 sm:py-16 lg:gap-16"
            :class="
                photo
                    ? 'lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]'
                    : undefined
            "
        >
            <img
                v-if="photo"
                :src="photo.src"
                :alt="photo.alt"
                width="1600"
                height="1067"
                loading="lazy"
                decoding="async"
                class="aspect-[4/3] w-full rounded-[1.5rem] object-cover ring-1 ring-white/15"
            />
            <div :class="photo ? undefined : 'mx-auto max-w-3xl text-center'">
                <h2
                    class="text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    Menos trabalho sobre os dados. Mais tempo para trabalhar com
                    os alunos.
                </h2>
                <p
                    class="mt-5 max-w-xl text-lg leading-relaxed text-pretty text-blue-100"
                    :class="photo ? undefined : 'mx-auto'"
                >
                    O Lapispro não pretende substituir o professor. Pretende
                    dar-lhe melhor informação, melhor organização e mais tempo
                    para tomar decisões pedagógicas com confiança.
                </p>
                <p
                    class="mt-6 text-xl font-semibold tracking-tight text-balance sm:text-2xl"
                >
                    O professor decide. O Lapispro simplifica o caminho.
                </p>
                <div
                    class="mt-8 flex flex-wrap items-center gap-3"
                    :class="photo ? undefined : 'justify-center'"
                >
                    <Button
                        as-child
                        size="lg"
                        class="group/cta rounded-full bg-white px-6 text-[#183B8F] hover:bg-blue-50"
                    >
                        <Link :href="authenticated ? dashboard() : register()">
                            {{
                                authenticated
                                    ? 'Ir para o painel'
                                    : 'Começar gratuitamente'
                            }}
                            <ArrowRight
                                aria-hidden="true"
                                class="transition-transform duration-300 group-hover/cta:translate-x-0.5"
                            />
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="lg"
                        class="rounded-full border-white/40 bg-transparent px-6 text-white transition-transform duration-300 hover:bg-white/10 hover:text-white motion-safe:hover:-translate-y-0.5"
                    >
                        <Link href="/planos">Conhecer o Pro</Link>
                    </Button>
                </div>
                <ul
                    class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-sm text-blue-100"
                    :class="photo ? undefined : 'justify-center'"
                >
                    <li
                        v-for="chip in chips"
                        :key="chip"
                        class="inline-flex items-center gap-1.5"
                    >
                        <Check
                            aria-hidden="true"
                            class="size-4 text-emerald-300"
                        />
                        {{ chip }}
                    </li>
                    <li v-if="!authenticated">
                        <Link
                            :href="login()"
                            class="rounded font-medium text-white underline underline-offset-4 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                            >Já tenho conta</Link
                        >
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
