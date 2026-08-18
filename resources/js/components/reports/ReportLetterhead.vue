<script setup lang="ts">
type Identity = {
    name: string;
    header_lines: string[];
    logo_url: string | null;
    is_configured: boolean;
};

defineProps<{ identity: Identity }>();
</script>

<!--
    The school's letterhead on a document.

    IT PRINTS WHAT EXISTS AND NOTHING ELSE (§50). `header_lines` arrives already
    assembled and already free of blanks — the postal code and the locality are
    one line when both exist and either alone when only one does — so there is
    no logic here deciding what to show. Adding any would give this component an
    opinion about the school's identity, which belongs to DocumentIdentity.
-->
<template>
    <header class="flex items-start gap-4">
        <img v-if="identity.logo_url" :src="identity.logo_url" alt="" class="size-16 shrink-0 object-contain" />

        <div class="min-w-0 flex-1">
            <p class="font-semibold leading-snug break-words">{{ identity.name }}</p>
            <p
                v-for="line in identity.header_lines"
                :key="line"
                class="mt-0.5 text-xs leading-relaxed break-words text-muted-foreground"
            >
                {{ line }}
            </p>
        </div>
    </header>

    <p v-if="!identity.is_configured" class="text-xs text-muted-foreground">
        A identidade da escola ainda não foi configurada; o documento usa o nome da conta. Pode preenchê-la em
        Definições → Identidade da escola.
    </p>
</template>
