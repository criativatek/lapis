import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { SelfAssessmentReading } from '@/lib/selfAssessmentReading';
import SelfAssessmentValue from './SelfAssessmentValue.vue';

type Reading = NonNullable<SelfAssessmentReading>;

const bands = [
    { sequence: 1, is_negative: true },
    { sequence: 2, is_negative: false },
    { sequence: 3, is_negative: false },
    { sequence: 4, is_negative: false },
    { sequence: 5, is_negative: false },
];

const current: Reading = { code: '5', label: 'Muito Bom', display: '5', sequence: 5, is_negative: false, scale: 'Escala 1 a 5', previous_criteria: false, earlier: false };
const previous: Reading = { code: '70', label: '70%', display: '70%', sequence: null, is_negative: false, scale: 'Percentagem (0 a 100)', previous_criteria: true, criteria: 'Avaliação final · versão 1 · Percentagem (0 a 100)', earlier: false };
const earlier: Reading = { ...previous, earlier: true };

describe('SelfAssessmentValue', () => {
    it('renders nothing without a reading', () => {
        expect(mount(SelfAssessmentValue, { props: { reading: null } }).find('[data-test="self-assessment-value"]').exists()).toBe(false);
    });

    it('shows a current reading as its value, named in full for assistive technology, with the band colour', () => {
        const wrapper = mount(SelfAssessmentValue, { props: { reading: current, compact: true, bands } });

        expect(wrapper.text()).toBe('5');
        expect(wrapper.attributes('aria-label')).toBe('Autoavaliação do aluno: 5 — Muito Bom');
        expect(wrapper.attributes('title')).toBe('Autoavaliação do aluno: 5 — Muito Bom');
        expect(wrapper.classes().join(' ')).toContain('emerald');
    });

    it('marks a compact previous-criteria reading «(ant.)» visually, hidden from assistive technology, and keeps it neutral', () => {
        const wrapper = mount(SelfAssessmentValue, { props: { reading: previous, compact: true, bands, domain: 'Atitudes' } });

        expect(wrapper.text()).toBe('70% (ant.)');
        expect(wrapper.get('[aria-hidden="true"]').text()).toBe('(ant.)');
        expect(wrapper.attributes('aria-label')).toContain('Autoavaliação do aluno — Atitudes: 70% (Percentagem (0 a 100)) — respondida com critérios anteriores: Avaliação final');
        expect(wrapper.classes().join(' ')).toContain('bg-muted');
        expect(wrapper.classes().join(' ')).not.toMatch(/emerald|red|blue|amber/);
    });

    it('marks a compact earlier reading and explains it in the accessible name', () => {
        const wrapper = mount(SelfAssessmentValue, { props: { reading: earlier, compact: true, bands } });

        expect(wrapper.text()).toBe('70% (ant.)');
        expect(wrapper.attributes('aria-label')).toMatch(/^A nova autoavaliação não tem proposta global\. Proposta anterior: 70%/);
    });

    it('writes the whole wording when it is not compact, with no separate mark', () => {
        const wrapper = mount(SelfAssessmentValue, { props: { reading: earlier } });

        expect(wrapper.text()).toBe('anterior: 70% · critérios anteriores');
        expect(wrapper.find('[aria-hidden="true"]').exists()).toBe(false);
    });

    it('puts a prefix before the value and can be a superscript', () => {
        const wrapper = mount(SelfAssessmentValue, { props: { reading: current, compact: true, prefix: 'Auto ', tag: 'sup' } });

        expect(wrapper.element.tagName).toBe('SUP');
        expect(wrapper.text()).toBe('Auto 5');
    });

    it('uses no colour at all when no bands are given', () => {
        expect(mount(SelfAssessmentValue, { props: { reading: current, compact: true } }).classes()).toEqual([]);
    });
});
