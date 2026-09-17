import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { h, ref } from 'vue';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { SharedNavItem, SharedNavSection } from '@/types';
import NavProfessor from './NavProfessor.vue';

const currentUrl = ref('/dashboard');

vi.mock('@inertiajs/vue3', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        Link: defineComponent({
            props: { href: { type: String, default: '' } },
            setup:
                (props, { slots, attrs }) =>
                () =>
                    h('a', { ...attrs, href: props.href }, slots.default?.()),
        }),
    };
});

vi.mock('@/composables/useCurrentUrl', () => ({
    useCurrentUrl: () => ({
        currentUrl,
        isCurrentUrl: (href: string) => currentUrl.value === href,
    }),
}));

function item(overrides: Partial<SharedNavItem>): SharedNavItem {
    return {
        key: 'x',
        label: 'X',
        description: null,
        match: [],
        icon: 'LayoutGrid',
        priority: false,
        phase: 1,
        href: '/x',
        built: true,
        ...overrides,
    };
}

const section: SharedNavSection = {
    label: 'Organização do ano letivo',
    items: [
        item({ key: 'teacher-timetable', label: 'Horário do Professor', href: '/timetable' }),
        item({ key: 'lessons', label: 'Aulas e Sumários', icon: 'Presentation', priority: true, href: '/lessons' }),
    ],
};

function menu(url: string, collapsed = false) {
    currentUrl.value = url;

    return mount({
        render: () => h(SidebarProvider, { defaultOpen: !collapsed }, () => h(NavProfessor, { section })),
    });
}

describe('NavProfessor — prioridade de «Aulas e Sumários»', () => {
    it('noutra secção: marcado como prioritário, mas não como ativo', () => {
        const wrapper = menu('/dashboard');
        const lessons = wrapper.find('a[href="/lessons"]');

        expect(lessons.attributes('data-priority')).toBe('true');
        expect(lessons.attributes('data-active')).not.toBe('true');
        expect(lessons.classes()).toContain('before:bg-blue-400');
        expect(wrapper.find('a[href="/timetable"]').attributes('data-priority')).toBeUndefined();
    });

    it('dentro de Aulas: o ativo sobrepõe-se com outra estrutura (barra âmbar, preenchimento)', () => {
        const lessons = menu('/lessons').find('a[href="/lessons"]');

        expect(lessons.attributes('data-active')).toBe('true');
        expect(lessons.classes()).toContain('data-[active=true]:before:bg-sidebar-primary');
        expect(lessons.classes()).toContain('data-[active=true]:bg-sidebar-accent');
    });

    it('um item comum ativo não ganha a marca de prioridade', () => {
        const timetable = menu('/timetable').find('a[href="/timetable"]');

        expect(timetable.attributes('data-active')).toBe('true');
        expect(timetable.classes()).not.toContain('before:bg-blue-400');
    });

    it('colapsado: continua um link com ícone, marca e nome acessível', () => {
        const lessons = menu('/dashboard', true).find('a[href="/lessons"]');

        expect(lessons.find('svg').exists()).toBe(true);
        expect(lessons.attributes('data-priority')).toBe('true');
        expect(lessons.text()).toContain('Aulas e Sumários');
        expect(lessons.attributes('tabindex')).toBeUndefined();
    });
});
