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
        expect(wrapper.classes()).toContain('max-w-[46rem]');
        expect(wrapper.find('#body').text()).toBe('Conteúdo');
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

    it('follows the orientation prop for the page size and drops the reading width', () => {
        const wrapper = mountDocument({ orientation: 'landscape' });

        expect(wrapper.attributes('data-orientation')).toBe('landscape');
        expect(wrapper.classes()).not.toContain('max-w-[46rem]');
        expect(pageStyles()[0].textContent).toContain('size: A4 landscape');
    });
});
