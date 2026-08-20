<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Check, LogOut, Settings } from '@lucide/vue';
import { computed } from 'vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { logout } from '@/routes';
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
                Settings
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
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
            Log out
        </Link>
    </DropdownMenuItem>
</template>
