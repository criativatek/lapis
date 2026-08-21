<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import ClosureCard from '@/components/organization/ClosureCard.vue';
import type { ClosureStatus } from '@/types/closure';

type Organization = {
    name: string;
    type: string;
};

const props = defineProps<{
    organization: Organization;
    closure: ClosureStatus | null;
    closureRetentionDays: number;
}>();
</script>

<template>
    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading title="Administração Institucional" :description="props.organization.name" />

        <p class="text-sm text-muted-foreground">
            O encerramento abaixo afeta apenas a organização {{ props.organization.name }} — não a tua conta pessoal. Para encerrar a tua
            conta, vai a Configurações.
        </p>

        <ClosureCard :closure="props.closure" :closure-retention-days="props.closureRetentionDays" />
    </div>
</template>
