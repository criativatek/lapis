<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Building2, Trash2, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SettingsLayout from '@/layouts/settings/Layout.vue';


/**
 * The school as it will appear on a document.
 *
 * CONFIGURATION, NOT GENERATION. Nothing here renders a report — what it
 * produces is the letterhead every future document will be handed, so that a
 * teacher types their school's name once instead of once per report.
 *
 * THE PREVIEW IS THE SAME PAYLOAD THE DOCUMENT GETS. It is not a mock-up
 * assembled in the browser: `preview.header_lines` arrives already built by
 * DocumentIdentity, so what is on screen and what will be on paper cannot
 * drift apart.
 */

type Identity = {
    official_name: string | null;
    short_name: string | null;
    address: string | null;
    postal_code: string | null;
    locality: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    school_code: string | null;
    tax_number: string | null;
    department: string | null;
    footer_note: string | null;
};

const props = defineProps<{
    identity: Identity;
    preview: {
        name: string;
        header_lines: string[];
        footer_note: string | null;
        has_logo: boolean;
        logo_url: string | null;
        is_configured: boolean;
    };
    canEdit: boolean;
    organizationName: string;
}>();

/**
 * The form holds strings, never nulls: an input has no «absent» value, and the
 * server already reads an empty field as «not filled in» rather than as «».
 */
type IdentityForm = Record<keyof Identity, string>;

const form = useForm<IdentityForm>(
    Object.fromEntries(
        Object.entries(props.identity).map(([key, value]) => [key, value ?? '']),
    ) as IdentityForm,
);

function save(): void {
    form.put('/settings/school-identity', { preserveScroll: true });
}

// The logo is its own request: an upload and a text edit fail in different
// ways, and a rejected image must never discard an address just typed.
const logoInput = ref<HTMLInputElement | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });

function onLogoChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    if (file === null) {
        return;
    }

    logoForm.logo = file;
    logoForm.post('/settings/school-identity/logo', {
        forceFormData: true,
        preserveScroll: true,
        // Always cleared, so picking the same file again after a rejection
        // still fires a change event.
        onFinish: () => {
            logoForm.logo = null;

            if (logoInput.value) {
                logoInput.value.value = '';
            }
        },
    });
}

function removeLogo(): void {
    if (!confirm('Remover o logótipo da escola?')) {
        return;
    }

    router.delete('/settings/school-identity/logo', { preserveScroll: true });
}

/** A cache-buster, so replacing the logo shows the new one immediately. */
const logoSrc = computed<string | null>(() => (
    props.preview.logo_url === null ? null : `${props.preview.logo_url}?v=${Date.now()}`
));
</script>

<template>
    <Head title="Identidade da escola" />

    <SettingsLayout>
        <div class="space-y-6">
            <Heading
                variant="small"
                title="Identidade da escola"
                description="Os dados institucionais que encabeçam os relatórios e outros documentos gerados pelo LÁPIS."
            />

            <p
                v-if="!canEdit"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
                role="status"
            >
                Estes dados são geridos por quem administra a organização. Vê aqui a identidade que os
                seus documentos vão usar, mas não a pode alterar.
            </p>

            <div class="grid gap-6 lg:grid-cols-5">
                <!-- ------------------------------------------- o formulário -->
                <form class="space-y-6 lg:col-span-3" @submit.prevent="save">
                    <!-- The logo, on its own request. -->
                    <section class="space-y-3">
                        <Label for="logo-input">Logótipo</Label>

                        <div class="flex flex-wrap items-center gap-4">
                            <div
                                class="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/30"
                            >
                                <img
                                    v-if="logoSrc"
                                    :src="logoSrc"
                                    :alt="`Logótipo de ${preview.name}`"
                                    class="size-full object-contain p-2"
                                />
                                <Building2 v-else aria-hidden="true" class="size-8 text-muted-foreground/50" />
                            </div>

                            <div class="space-y-2">
                                <input
                                    id="logo-input"
                                    ref="logoInput"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    class="sr-only"
                                    :disabled="!canEdit"
                                    @change="onLogoChange"
                                />

                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        :disabled="!canEdit || logoForm.processing"
                                        @click="logoInput?.click()"
                                    >
                                        <Upload aria-hidden="true" class="mr-1.5 size-3.5" />
                                        {{ preview.has_logo ? 'Substituir' : 'Carregar' }}
                                    </Button>

                                    <Button
                                        v-if="preview.has_logo"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        :disabled="!canEdit"
                                        @click="removeLogo"
                                    >
                                        <Trash2 aria-hidden="true" class="mr-1.5 size-3.5" />
                                        Remover
                                    </Button>
                                </div>

                                <p class="text-xs text-muted-foreground">
                                    PNG, JPG ou WebP, até 2&nbsp;MB. Fundo transparente resulta melhor
                                    nos documentos.
                                </p>

                                <InputError :message="logoForm.errors.logo" />
                            </div>
                        </div>
                    </section>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="official_name">Nome oficial</Label>
                            <Input
                                id="official_name"
                                v-model="form.official_name"
                                type="text"
                                :disabled="!canEdit"
                                placeholder="Agrupamento de Escolas de…"
                            />
                            <InputError :message="form.errors.official_name" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="short_name">Nome curto</Label>
                            <Input id="short_name" v-model="form.short_name" type="text" :disabled="!canEdit" />
                            <p class="text-xs text-muted-foreground">Opcional, para onde não couber o nome completo.</p>
                            <InputError :message="form.errors.short_name" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="address">Morada</Label>
                            <Input id="address" v-model="form.address" type="text" :disabled="!canEdit" />
                            <InputError :message="form.errors.address" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="postal_code">Código postal</Label>
                            <Input id="postal_code" v-model="form.postal_code" type="text" :disabled="!canEdit" />
                            <InputError :message="form.errors.postal_code" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="locality">Localidade</Label>
                            <Input id="locality" v-model="form.locality" type="text" :disabled="!canEdit" />
                            <InputError :message="form.errors.locality" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="phone">Telefone</Label>
                            <Input id="phone" v-model="form.phone" type="tel" :disabled="!canEdit" />
                            <InputError :message="form.errors.phone" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">Email institucional</Label>
                            <Input id="email" v-model="form.email" type="email" :disabled="!canEdit" />
                            <InputError :message="form.errors.email" />
                        </div>

                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="website">Website</Label>
                            <Input
                                id="website"
                                v-model="form.website"
                                type="text"
                                :disabled="!canEdit"
                                placeholder="aeexemplo.pt"
                            />
                            <InputError :message="form.errors.website" />
                        </div>
                    </div>

                    <!-- Administrative detail nobody has to fill in. Folded, so
                         it does not make the page look like it demands more
                         than it does. -->
                    <details class="rounded-lg border border-dashed border-border">
                        <summary class="cursor-pointer px-4 py-3 text-sm font-medium select-none">
                            Dados administrativos
                            <span class="ml-1 text-xs font-normal text-muted-foreground">· opcionais</span>
                        </summary>

                        <div class="grid gap-4 border-t border-border px-4 py-4 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="school_code">Código da escola</Label>
                                <Input id="school_code" v-model="form.school_code" type="text" :disabled="!canEdit" />
                                <InputError :message="form.errors.school_code" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="tax_number">NIF</Label>
                                <Input id="tax_number" v-model="form.tax_number" type="text" :disabled="!canEdit" />
                                <InputError :message="form.errors.tax_number" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="department">Departamento</Label>
                                <Input id="department" v-model="form.department" type="text" :disabled="!canEdit" />
                                <InputError :message="form.errors.department" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="country">País</Label>
                                <Input id="country" v-model="form.country" type="text" :disabled="!canEdit" />
                                <InputError :message="form.errors.country" />
                            </div>

                            <div class="grid gap-2 sm:col-span-2">
                                <Label for="footer_note">Texto de rodapé</Label>
                                <Input id="footer_note" v-model="form.footer_note" type="text" :disabled="!canEdit" />
                                <p class="text-xs text-muted-foreground">
                                    Aparecerá no fim dos documentos, se o preencher.
                                </p>
                                <InputError :message="form.errors.footer_note" />
                            </div>
                        </div>
                    </details>

                    <div v-if="canEdit" class="flex items-center gap-3">
                        <Button type="submit" :disabled="form.processing">Guardar alterações</Button>
                        <span v-if="form.recentlySuccessful" class="text-sm text-muted-foreground" role="status">
                            Guardado.
                        </span>
                    </div>
                </form>

                <!-- ------------------------------------- a pré-visualização -->
                <aside class="lg:col-span-2">
                    <div class="sticky top-4 space-y-3">
                        <h2 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Pré-visualização em documentos
                        </h2>

                        <!-- Deliberately plain: this is a letterhead, not a
                             dashboard card. What it shows is exactly what a
                             document will print, and nothing empty appears. -->
                        <div class="rounded-xl border border-border bg-card p-5 shadow-sm">
                            <div class="flex items-start gap-4">
                                <img
                                    v-if="logoSrc"
                                    :src="logoSrc"
                                    alt=""
                                    class="size-14 shrink-0 object-contain"
                                />

                                <div class="min-w-0">
                                    <p class="font-semibold leading-tight">{{ preview.name }}</p>
                                    <p
                                        v-for="line in preview.header_lines"
                                        :key="line"
                                        class="mt-0.5 text-xs leading-relaxed text-muted-foreground"
                                    >
                                        {{ line }}
                                    </p>
                                </div>
                            </div>

                            <div class="my-4 h-px bg-border"></div>

                            <p class="text-xs text-muted-foreground">
                                Relatório de Turma · 7.º A · Português
                            </p>

                            <p v-if="preview.footer_note" class="mt-4 border-t border-border pt-3 text-[11px] text-muted-foreground">
                                {{ preview.footer_note }}
                            </p>
                        </div>

                        <p v-if="!preview.is_configured" class="text-xs text-muted-foreground">
                            Ainda não configurou a identidade da escola. Enquanto não o fizer, os documentos
                            usam <strong>{{ organizationName }}</strong>, o nome da sua conta.
                        </p>
                        <p v-else class="text-xs text-muted-foreground">
                            Guardado depois de gravar. Os relatórios e exportações vão usar estes dados.
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </SettingsLayout>
</template>
