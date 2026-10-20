import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import PrintableDocument from './PrintableDocument.vue';

const wrappers: VueWrapper[] = [];

function mountDocument(props: Record<string, unknown> = {}) {
    const wrapper = mount(PrintableDocument, { props, slots: { default: '<p id="body">Conteúdo</p>' } });
    wrappers.push(wrapper);

    return wrapper;
}

function pageStyles(): HTMLStyleElement[] {
    return Array.from(document.head.querySelectorAll<HTMLStyleElement>('style[data-document-print-page]'));
}

describe('PrintableDocument', () => {
    afterEach(() => {
        wrappers.forEach((wrapper) => wrapper.unmount());
        wrappers.length = 0;
    });

    it('renders the slot inside the print hook, portrait by default', () => {
        const wrapper = mountDocument();

        expect(wrapper.classes()).toContain('document-print');
        expect(wrapper.attributes('data-orientation')).toBe('portrait');
        expect(wrapper.find('#body').text()).toBe('Conteúdo');
    });

    it('carries no on-screen look of its own, only the classes the page passes', () => {
        const bare = mountDocument();

        expect(bare.classes()).toEqual(['document-print']);

        const styled = mount(PrintableDocument, {
            attrs: { class: 'report-print rounded-lg border' },
            slots: { default: '<p>x</p>' },
        });
        wrappers.push(styled);

        expect(styled.classes()).toEqual(expect.arrayContaining(['document-print', 'report-print', 'rounded-lg', 'border']));
    });

    it('injects an A4 portrait @page on mount and removes it on unmount', () => {
        const wrapper = mountDocument();

        expect(pageStyles()).toHaveLength(1);
        expect(pageStyles()[0].textContent).toContain('size: A4 portrait');
        expect(pageStyles()[0].textContent).toContain('margin: 18mm 16mm');

        wrapper.unmount();
        wrappers.length = 0;

        expect(pageStyles()).toHaveLength(0);
    });

    it('follows the orientation prop for the page size', () => {
        const wrapper = mountDocument({ orientation: 'landscape' });

        expect(wrapper.attributes('data-orientation')).toBe('landscape');
        expect(pageStyles()[0].textContent).toContain('size: A4 landscape');
        expect(pageStyles()[0].textContent).toContain('margin: 18mm 16mm');
    });

    it('uses a 10mm margin when compact', () => {
        const wrapper = mountDocument({ orientation: 'landscape', compact: true });

        expect(wrapper.attributes('data-compact')).toBe('true');
        expect(pageStyles()[0].textContent).toContain('size: A4 landscape; margin: 10mm;');
    });

    it('rewrites the @page when the props change without remounting', async () => {
        const wrapper = mountDocument();

        await wrapper.setProps({ orientation: 'landscape', compact: true });

        expect(pageStyles()).toHaveLength(1);
        expect(pageStyles()[0].textContent).toContain('size: A4 landscape; margin: 10mm;');

        await wrapper.setProps({ orientation: 'portrait', compact: false });

        expect(pageStyles()).toHaveLength(1);
        expect(pageStyles()[0].textContent).toContain('size: A4 portrait; margin: 18mm 16mm;');
    });

    it('leaves exactly the paper of the document the user navigated to', () => {
        const first = mountDocument();
        first.unmount();
        wrappers.length = 0;

        mountDocument({ orientation: 'landscape', compact: true });

        expect(pageStyles()).toHaveLength(1);
        expect(pageStyles()[0].textContent).toContain('size: A4 landscape; margin: 10mm;');
    });
});
