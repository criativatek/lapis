import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Notebook from './Notebook.vue';

const post = vi.fn();
const put = vi.fn();
const get = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
    Link: { template: '<a><slot /></a>' },
    router: {
        post: (...args: unknown[]) => post(...args),
        put: (...args: unknown[]) => put(...args),
        get: (...args: unknown[]) => get(...args),
        patch: () => {},
        delete: () => {},
        on: () => () => {},
    },
}));

const wrappers: VueWrapper[] = [];

function entryFixture(overrides: Record<string, unknown> = {}) {
    return {
        ulid: '01JENTRY1',
        title: null,
        body: 'Turma muito faladora.',
        is_pinned: false,
        created_at: '2026-10-09T10:00:00+01:00',
        edited_at: null,
        lock_version: 0,
        ...overrides,
    };
}

function mountPage(entries: ReturnType<typeof entryFixture>[] = [], totalEntries = entries.length, q = '') {
    const wrapper = mount(Notebook, {
        attachTo: document.body,
        props: {
            schoolClass: { ulid: '01JCLASS', label: '7.º A', subject: 'Matemática', academic_year: '2026/2027', archived: false },
            entries: { data: entries, links: [], total: entries.length },
            filters: { q },
            totalEntries,
            can: { write: true },
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

function button(wrapper: VueWrapper, text: string) {
    return wrapper.findAll('button').find((candidate) => candidate.text().includes(text));
}

afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    post.mockReset();
    put.mockReset();
    get.mockReset();
    vi.restoreAllMocks();
});

describe('Caderno da turma', () => {
    it('labels the page links in Portuguese, never with the raw translation key', async () => {
        const wrapper = mountPage([entryFixture()], 25);

        await wrapper.setProps({
            entries: {
                data: [entryFixture()],
                total: 25,
                links: [
                    { url: null, label: 'pagination.previous', active: false },
                    { url: '/classes/01JCLASS/notebook?page=1', label: '1', active: true },
                    { url: '/classes/01JCLASS/notebook?page=2', label: '2', active: false },
                    { url: '/classes/01JCLASS/notebook?page=2', label: 'pagination.next', active: false },
                ],
            },
        });

        const nav = wrapper.find('nav[aria-label="Páginas do caderno"]');

        expect(nav.text()).toContain('‹ Anterior');
        expect(nav.text()).toContain('Seguinte ›');
        expect(nav.text()).not.toContain('pagination.');
    });

    it('shows the empty state and the privacy line', () => {
        const wrapper = mountPage();

        expect(wrapper.text()).toContain('Caderno da turma');
        expect(wrapper.text()).toContain('Ainda não tens registos neste caderno.');
        expect(wrapper.text()).toContain('Visível apenas para ti.');
        expect(wrapper.find('input[type="search"]').exists()).toBe(false);
    });

    it('opens the composer with «Adicionar registo»', async () => {
        const wrapper = mountPage();

        expect(wrapper.find('textarea').exists()).toBe(false);

        await button(wrapper, 'Adicionar registo')?.trigger('click');

        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.text()).toContain('(opcional)');
    });

    it('refuses a blank body with the inline error and sends no request', async () => {
        const wrapper = mountPage();

        await button(wrapper, 'Adicionar registo')?.trigger('click');
        await wrapper.find('textarea').setValue('  \u00a0 \t ');
        await wrapper.find('form:not([role="search"])').trigger('submit');

        expect(wrapper.text()).toContain('Escreve o registo antes de guardar.');
        expect(post).not.toHaveBeenCalled();
    });

    it('keeps the typed text and says so when the connection fails', async () => {
        const wrapper = mountPage();

        await button(wrapper, 'Adicionar registo')?.trigger('click');
        await wrapper.find('textarea').setValue('Reunião com os encarregados de educação.');
        await wrapper.find('form:not([role="search"])').trigger('submit');

        expect(post).toHaveBeenCalledTimes(1);
        const [url, payload, options] = post.mock.calls[0] as [
            string,
            { title: string; body: string },
            { onNetworkError: () => boolean; onFinish: () => void },
        ];

        expect(url).toBe('/classes/01JCLASS/notebook');
        expect(payload.body).toBe('Reunião com os encarregados de educação.');

        expect(options.onNetworkError()).toBe(false);
        options.onFinish();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Não foi possível guardar: a ligação falhou.');
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Reunião com os encarregados de educação.');
    });

    it('asks before discarding a dirty draft and keeps it when declined', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mountPage();

        await button(wrapper, 'Adicionar registo')?.trigger('click');
        await wrapper.find('textarea').setValue('Rascunho importante');
        await button(wrapper, 'Cancelar')?.trigger('click');

        expect(confirm).toHaveBeenCalledWith('Tens alterações por guardar. Queres descartá-las?');
        expect((wrapper.find('textarea').element as HTMLTextAreaElement).value).toBe('Rascunho importante');
    });

    it('closes without asking when there is nothing to lose', async () => {
        const confirm = vi.spyOn(window, 'confirm');
        const wrapper = mountPage();

        await button(wrapper, 'Adicionar registo')?.trigger('click');
        await button(wrapper, 'Cancelar')?.trigger('click');

        expect(confirm).not.toHaveBeenCalled();
        expect(wrapper.find('textarea').exists()).toBe(false);
    });

    it('marks a pinned entry', () => {
        const wrapper = mountPage([entryFixture({ is_pinned: true, title: 'Prioridade' })]);

        expect(wrapper.text()).toContain('Fixado');
        expect(wrapper.text()).toContain('Prioridade');
        expect(wrapper.find('button[aria-label="Desafixar"]').exists()).toBe(true);
    });

    it('searches through the server with the term', async () => {
        const wrapper = mountPage([entryFixture()]);

        await wrapper.find('input[type="search"]').setValue('faladora');
        await wrapper.find('form[role="search"]').trigger('submit');

        expect(get).toHaveBeenCalledTimes(1);
        const [url, data] = get.mock.calls[0] as [string, { q: string }];

        expect(url).toBe('/classes/01JCLASS/notebook');
        expect(data).toEqual({ q: 'faladora' });
    });

    it('explains an empty search instead of showing the empty notebook', () => {
        const wrapper = mountPage([], 3, 'xyz');

        expect(wrapper.text()).toContain('Nenhum registo corresponde a «xyz».');
        expect(wrapper.text()).toContain('Limpar pesquisa');
        expect(wrapper.text()).not.toContain('Ainda não tens registos neste caderno.');
    });
});
