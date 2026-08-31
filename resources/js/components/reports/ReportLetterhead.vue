<script setup lang="ts">
import { ref } from 'vue';

type Identity = {
    name: string;
    header_lines: string[];
    logo_url: string | null;
    is_configured: boolean;
};

defineProps<{ identity: Identity }>();

// A URL that 404s — a logo removed from the disk, a document finalized on
// another installation — must leave nothing behind. The browser's own broken
// image placeholder is a grey box in the letterhead of a school's report, which
// reads as a fault in the document rather than as a missing file.
const logoFailed = ref(false);
</script>

<!--
    The school's letterhead on a document.

    IT PRINTS WHAT EXISTS AND NOTHING ELSE (§50). `header_lines` arrives already
    assembled and already free of blanks — the address and the locality are one
    line, the phone and the email the next — so there is no logic here deciding
    what to show. Adding any would give this component an opinion about the
    school's identity, which belongs to DocumentIdentity.

    IT IS SMALLER THAN THE DOCUMENT'S TITLE. A letterhead identifies the school;
    it is not what the document is called, and a name set in the same weight as
    the report's own title reads as the report's title.
-->
<template>
    <header class="flex items-start gap-3">
        <img
            v-if="identity.logo_url && !logoFailed"
            :src="identity.logo_url"
            alt=""
            class="size-12 shrink-0 object-contain"
            @error="logoFailed = true"
        />

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold leading-snug break-words">{{ identity.name }}</p>
            <p
                v-for="line in identity.header_lines"
                :key="line"
                class="mt-0.5 text-[11px] leading-snug break-words text-muted-foreground"
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
