import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import StudentAvatar from './StudentAvatar.vue';

describe('StudentAvatar', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    const photoProps = {
        photoUrl: '/students/1/photo',
        size: 'md' as const,
        zoomable: true,
        studentName: 'Álvaro Simões',
    };

    it('renders a labelled thumbnail button when zoom is enabled for a photo', () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });

        const button = wrapper.get('button');

        expect(button.attributes('aria-label')).toBe('Ampliar fotografia de Álvaro Simões');
        expect(button.get('img').attributes('src')).toBe('/students/1/photo');
        expect(button.classes()).toContain('size-[34px]');
    });

    it('renders the authorized photo in a constrained dialog', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });

        await wrapper.get('button').trigger('click');

        const dialogImage = document.body.querySelector(
            '[data-slot="dialog-content"] img',
        );

        expect(dialogImage?.getAttribute('src')).toBe('/students/1/photo');
        expect(dialogImage?.getAttribute('alt')).toBe(
            'Fotografia de Álvaro Simões',
        );
        expect(dialogImage?.className).toContain(
            'max-h-[calc(100vh-5rem)]',
        );
        expect(dialogImage?.className).toContain(
            'max-w-[calc(100vw-2rem)]',
        );
        expect(dialogImage?.className).toContain('object-contain');
    });

    it('keeps the existing fallback without a trigger when there is no photo', () => {
        const wrapper = mount(StudentAvatar, {
            props: { zoomable: true, studentName: 'Álvaro Simões' },
            attachTo: document.body,
        });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    });

    it('treats an empty photo URL as no photo', () => {
        const wrapper = mount(StudentAvatar, {
            props: {
                photoUrl: '',
                zoomable: true,
                studentName: 'Álvaro Simões',
            },
            attachTo: document.body,
        });

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    });

    it.each([
        ['Enter', 'Enter'],
        ['Space', ' '],
    ])('opens from %s keyboard activation', async (_label, key) => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });
        const button = wrapper.get('button');

        await button.trigger('keydown', { key });

        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).not.toBeNull();
    });

    it('closes with the accessible X button', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });

        await wrapper.get('button').trigger('click');
        const closeButton = document.body.querySelector(
            '[data-slot="dialog-close"]',
        ) as HTMLButtonElement;

        closeButton.click();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).toBeNull();
    });

    it('closes with Escape and returns focus to the thumbnail button', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });
        const button = wrapper.get('button');

        button.element.focus();
        await button.trigger('click');
        document.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
        );
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).toBeNull();
        expect(document.activeElement).toBe(button.element);
    });

    it('closes when the overlay is activated and returns focus to the button', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });
        const button = wrapper.get('button');

        button.element.focus();
        await button.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));
        const overlay = document.body.querySelector(
            '[data-slot="dialog-overlay"]',
        ) as HTMLElement;

        overlay.dispatchEvent(
            new MouseEvent('pointerdown', {
                bubbles: true,
                composed: true,
                button: 0,
            }),
        );
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(
            document.body.querySelector('[data-slot="dialog-content"]'),
        ).toBeNull();
        expect(document.activeElement).toBe(button.element);
    });

    it('returns focus after closing with the X button', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });
        const button = wrapper.get('button');

        button.element.focus();
        await button.trigger('click');
        const closeButton = document.body.querySelector(
            '[data-slot="dialog-close"]',
        ) as HTMLButtonElement;

        closeButton.click();
        await wrapper.vm.$nextTick();
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(document.activeElement).toBe(button.element);
    });

    it('replaces a failed photo with the fallback and removes zoom', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });

        await wrapper.get('img').trigger('error');

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    });

    it('falls back when the enlarged photo fails to load', async () => {
        const wrapper = mount(StudentAvatar, {
            props: photoProps,
            attachTo: document.body,
        });

        await wrapper.get('button').trigger('click');
        const enlargedPhoto = document.body.querySelector(
            '[data-slot="dialog-content"] img',
        ) as HTMLImageElement;

        enlargedPhoto.dispatchEvent(new Event('error'));
        await wrapper.vm.$nextTick();

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(true);
    });
});
