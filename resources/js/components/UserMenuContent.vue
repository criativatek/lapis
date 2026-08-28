<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Check, CircleHelp, LogOut, Settings, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
import { index as platformBackoffice } from '@/routes/admin/accounts';
import { index as helpIndex } from '@/routes/help';
import { edit } from '@/routes/profile';
import type { Auth, User } from '@/types';

type Props = {
    user: User;
};

defineProps<Props>();

const page = usePage();
const auth = computed(() => page.props.auth as Auth);

// Nothing extra to show — and nothing to click — for the person who has only
// ever had the one organization everybody starts with (§17 of the multi-user
// brief): no switcher, no current-organization line, exactly today's menu.
const hasMultipleOrganizations = computed(() => auth.value.organizations.length > 1);

// The SaaS operator's way back into the backoffice, and the only entry point
// the application offers — before this, /admin was reachable only by typing the
// URL. Deliberately NOT derived from `organization.is_owner` nor from any
// module: an institutional administrator administers an ORGANIZATION and must
// never see this. Hiding it is presentation; `EnsurePlatformAdmin` is the gate.
const isPlatformAdmin = computed(() => auth.value.is_platform_admin === true);

function switchTo(ulid: string): void {
    if (ulid === auth.value.organization?.ulid) {
        return;
    }

    router.post('/organizations/switch', { organization: ulid });
}

const handleLogout = () => {
    router.flushAll();
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>

    <template v-if="hasMultipleOrganizations">
        <DropdownMenuSeparator />
        <DropdownMenuLabel class="px-2 py-1 text-xs font-normal text-muted-foreground">Organização</DropdownMenuLabel>
        <DropdownMenuGroup>
            <DropdownMenuItem
                v-for="organization in auth.organizations"
                :key="organization.ulid"
                class="cursor-pointer gap-2"
                @click="switchTo(organization.ulid)"
            >
                <Building2 class="h-4 w-4 shrink-0 text-muted-foreground" />
                <span class="flex-1 truncate">{{ organization.name }}</span>
                <Check v-if="organization.ulid === auth.organization?.ulid" class="h-4 w-4 shrink-0" />
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>

    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Configurações
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <!-- Its own group, unconditional and independent of plan/module — help
         content is not a paid feature, so it never joins config/navigation.php's
         entitlement-filtered sidebar (see App\Http\Controllers\HelpController). -->
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="helpIndex()" data-test="help-link" prefetch>
                <CircleHelp class="mr-2 h-4 w-4" />
                Central de Ajuda
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>

    <!-- Its own group, never folded into «Configurações»: this leaves the
         teacher-facing app entirely, and the label says «da plataforma» so it
         cannot read as the tenant's «Administração Institucional». -->
    <template v-if="isPlatformAdmin">
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
            <DropdownMenuItem :as-child="true">
                <Link
                    class="block w-full cursor-pointer"
                    :href="platformBackoffice()"
                    data-test="platform-admin-link"
                >
                    <ShieldCheck class="mr-2 h-4 w-4" />
                    Administração da plataforma
                </Link>
            </DropdownMenuItem>
        </DropdownMenuGroup>
    </template>

    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full cursor-pointer"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Terminar sessão
        </Link>
    </DropdownMenuItem>
</template>
