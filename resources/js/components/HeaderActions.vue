<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, Search } from '@lucide/vue';
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { useInitials } from '@/composables/useInitials';

/**
 * A ponta direita da barra: pesquisar nos sumários, o suporte e a conta.
 *
 * NADA AQUI É NOVO. A pesquisa é a de «Aulas e Sumários» (só com `lessons`
 * legível — na Base não há sumários onde pesquisar); o sino lê o mesmo
 * `supportAwaitingReply` da faixa azul e leva aos pedidos; o menu da conta é
 * o `UserMenuContent` que o fundo da sidebar já abre — aqui para ficar à mão
 * quando a sidebar está recolhida ou fechada num telemóvel.
 */
const page = usePage();
const user = computed(() => page.props.auth.user);

const canReadLessons = computed(
    () => page.props.modules.includes('lessons') || page.props.readOnlyModules.includes('lessons'),
);

const awaiting = computed(() => Number(page.props.supportAwaitingReply ?? 0));
const supportLabel = computed(() => {
    if (awaiting.value === 0) {
        return 'Suporte';
    }

    return awaiting.value === 1 ? 'Suporte: 1 resposta por ler' : `Suporte: ${awaiting.value} respostas por ler`;
});

const { getInitials } = useInitials();
const showAvatar = computed(() => Boolean(user.value?.avatar));

const iconButton =
    'relative inline-flex size-11 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:size-9';
</script>

<template>
    <div class="ml-auto flex shrink-0 items-center gap-0.5 md:gap-1" data-header-actions>
        <Link
            v-if="canReadLessons"
            href="/lessons?view=semana&foco=pesquisa"
            :class="iconButton"
            aria-label="Pesquisar nos sumários"
            title="Pesquisar nos sumários"
            data-testid="header-search"
        >
            <Search class="size-[18px]" aria-hidden="true" />
        </Link>

        <Link href="/support" :class="iconButton" :aria-label="supportLabel" :title="supportLabel" data-testid="header-support">
            <Bell class="size-[18px]" aria-hidden="true" />
            <span
                v-if="awaiting > 0"
                class="absolute top-2 right-2 size-2 rounded-full bg-primary ring-2 ring-background md:top-1.5 md:right-1.5"
                aria-hidden="true"
                data-testid="header-support-dot"
            ></span>
        </Link>

        <DropdownMenu v-if="user">
            <DropdownMenuTrigger as-child>
                <button
                    type="button"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-md transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:size-9"
                    aria-label="Menu da conta"
                    data-testid="header-account"
                >
                    <Avatar class="size-8 overflow-hidden rounded-lg">
                        <AvatarImage v-if="showAvatar" :src="user.avatar!" :alt="user.name" />
                        <AvatarFallback class="rounded-lg text-xs font-semibold text-black dark:text-white">{{ getInitials(user.name) }}</AvatarFallback>
                    </Avatar>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent class="min-w-56 rounded-lg" align="end" :side-offset="4">
                <UserMenuContent :user="user" />
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
