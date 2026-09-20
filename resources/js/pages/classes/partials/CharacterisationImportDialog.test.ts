import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CharacterisationImportDialog from './CharacterisationImportDialog.vue';

/**
 * Covers the fixes from this pass:
 *   - F5: stale preview rows must not survive a file change.
 *   - F6: section_merges / already_active, computed server-side, must reach
 *     the screen instead of being silently dropped.
 *   - F8: an image chosen via the file picker goes through OCR, never `file`.
 *   - F9: 0 rows is an explained state, not a blank list.
 *   - F10: editing after a rich paste is visible and reversible, not a
 *     silent discard.
 */

const post = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (...args: unknown[]) => post(...args),
    },
}));

const extractTableFromImage = vi.fn();

vi.mock('./characterisation-image-extraction', () => ({
    extractTableFromImage: (...args: unknown[]) => extractTableFromImage(...args),
    ImageDecodeError: class ImageDecodeError extends Error {},
}));

// O diálogo real teleporta para fora da árvore (ver LessonOutcomePanel.test.ts
// para o mesmo padrão) — aqui renderiza-se inline para se poder inspecionar
// com `wrapper.find`.
vi.mock('@/components/ui/dialog', () => {
    const passthrough = { template: '<div><slot /></div>' };

    return {
        Dialog: passthrough,
        DialogClose: passthrough,
        DialogContent: passthrough,
        DialogDescription: passthrough,
        DialogFooter: passthrough,
        DialogHeader: passthrough,
        DialogTitle: passthrough,
    };
});

const students = [
    { ulid: '01JSTU1', name: 'Ana Silva', class_number: 1 },
    { ulid: '01JSTU2', name: 'Bruno Costa', class_number: 2 },
];

const wrappers: VueWrapper[] = [];

function mountDialog() {
    const wrapper = mount(CharacterisationImportDialog, {
        attachTo: document.body,
        props: {
            open: true,
            classUlid: '01JCLASS',
            students,
        },
    });

    wrappers.push(wrapper);

    return wrapper;
}

function jsonResponse(body: unknown, ok = true): Response {
    return {
        ok,
        json: () => Promise.resolve(body),
    } as Response;
}

function previewRow(rowNumber: number, name: string) {
    return {
        row_number: rowNumber,
        raw_name: name,
        raw_process_number: null,
        match: {
            state: 'confident',
            state_label: 'Confirmado',
            enrollment_ulid: '01JSTU1',
            matched_name: name,
            matched_by: 'name',
            candidates: [],
            preselected: true,
        },
        sections: { needs: `Secção de ${name}` },
        section_merges: {
            needs: {
                section: 'needs',
                action: 'add',
                action_label: 'A acrescentar',
                current_value: 'Texto já existente.',
                incoming_value: `Secção de ${name}`,
                merged_value: `Texto já existente. Secção de ${name}`,
            },
        },
        measures: [
            {
                raw_token: 'MED-1',
                confidence: 'high',
                confidence_label: 'Alta',
                level: 'universal',
                level_label: 'Universal',
                code: 'A1',
                code_label: 'Medida A1',
                unresolved_annotations: [],
                scope: 'measure',
                note: null,
                storable: true,
                family: null,
                family_label: null,
                has_structured_destination: true,
                already_active: false,
            },
        ],
        resources: [],
        unresolved: [],
    };
}

function previewResponse(
    rows: (ReturnType<typeof previewRow> | Record<string, unknown>)[],
    warnings: string[] = [],
    extra: Record<string, unknown> = {},
) {
    return {
        preview: {
            columns: [],
            rows,
            identifiable: true,
            tally: {},
        },
        source_kind: 'pasted_text',
        original_filename: null,
        warnings,
        sections: [{ key: 'needs', label: 'Necessidades' }],
        structural: { headers: [], rows: [] },
        show_structural_step: false,
        ...extra,
    };
}

beforeEach(() => {
    post.mockReset();
    extractTableFromImage.mockReset();
    document.cookie = 'XSRF-TOKEN=test-token';
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.unstubAllGlobals();
});

describe('CharacterisationImportDialog — F5 stale rows', () => {
    it('does not carry rows from a previous preview into the next one', async () => {
        const wrapper = mountDialog();

        const tableA = [previewRow(1, 'Aluno A1'), previewRow(2, 'Aluno A2'), previewRow(3, 'Aluno A3')];
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse(tableA)));

        await wrapper.find('#characterisation-paste').setValue('Aluno A1\tX\nAluno A2\tX\nAluno A3\tX');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Aluno A1');
        expect(wrapper.text()).toContain('Aluno A3');

        // "Escolher outro ficheiro" back to input, then preview a SMALLER table B.
        const backButton = wrapper.findAll('button').find((b) => b.text().includes('Escolher outro ficheiro'));
        await backButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).not.toContain('Aluno A1');

        const tableB = [previewRow(1, 'Aluno B1')];
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse(tableB)));

        await wrapper.find('#characterisation-paste').setValue('Aluno B1\tX');
        const previewButton2 = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton2?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Aluno B1');
        expect(wrapper.text()).not.toContain('Aluno A1');
        expect(wrapper.text()).not.toContain('Aluno A2');
        expect(wrapper.text()).not.toContain('Aluno A3');
    });
});

describe('CharacterisationImportDialog — F6 section merges and already-active measures', () => {
    it('renders já registado / a acrescentar and the append sentence', async () => {
        const wrapper = mountDialog();
        const rows = [previewRow(1, 'Aluno C1')];
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse(rows)));

        await wrapper.find('#characterisation-paste').setValue('Aluno C1\tX');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('JÁ REGISTADO');
        expect(wrapper.text()).toContain('Texto já existente.');
        expect(wrapper.text()).toContain('A ACRESCENTAR');
        expect(wrapper.text()).toContain('Será acrescentado à caracterização existente.');
        expect(wrapper.text()).toContain('Será adicionada a Estratégias e Medidas.');
    });

    it('shows a measure that is already active as not duplicated', async () => {
        const wrapper = mountDialog();
        const row = previewRow(1, 'Aluno C2');
        row.measures[0].already_active = true;

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse([row])));

        await wrapper.find('#characterisation-paste').setValue('Aluno C2\tX');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Já registada — não será duplicada.');
    });
});

describe('CharacterisationImportDialog — F8 file picker images go through OCR', () => {
    it('routes an image chosen via the file input to extractTableFromImage, not `file`', async () => {
        extractTableFromImage.mockResolvedValue({
            rows: [],
            source_type: 'image_upload',
            source_filename: 'tabela.png',
            warnings: [],
            extraction_confidence: 1,
        });

        const wrapper = mountDialog();
        const input = wrapper.find('input[type="file"]');
        const image = new File(['fake'], 'tabela.png', { type: 'image/png' });

        Object.defineProperty(input.element, 'files', {
            value: [image],
            configurable: true,
        });
        await input.trigger('change');
        await flushPromises();

        expect(extractTableFromImage).toHaveBeenCalledTimes(1);
        expect(extractTableFromImage.mock.calls[0][0]).toBe(image);
        expect(extractTableFromImage.mock.calls[0][1]).toMatchObject({ sourceKind: 'image_upload' });

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse([previewRow(1, 'Aluno D1')])));
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        const [, requestInit] = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[0];
        const body = requestInit.body as FormData;
        expect(body.get('file')).toBeNull();
        expect(body.get('extracted_table')).not.toBeNull();
    });
});

describe('CharacterisationImportDialog — F9 empty preview state', () => {
    it('explains 0 rows and offers a way back, keeping warnings visible', async () => {
        const wrapper = mountDialog();
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([], ['A legenda da tabela foi ignorada.'])),
        );

        await wrapper.find('#characterisation-paste').setValue('só cabeçalho');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Nenhuma linha ficou pronta a importar');
        expect(wrapper.text()).toContain('A legenda da tabela foi ignorada.');
        expect(wrapper.findAll('button').some((b) => b.text().includes('Escolher outro ficheiro'))).toBe(true);
    });
});

describe('CharacterisationImportDialog — F10 rich paste is visible and reversible', () => {
    it('disables the textarea while pastedHtml is set and offers an explicit discard', async () => {
        const wrapper = mountDialog();
        const textarea = wrapper.find('#characterisation-paste');

        await textarea.trigger('paste', {
            clipboardData: {
                types: ['text/html'],
                getData: (format: string) => (format === 'text/html' ? '<table><tr><td>Ana</td></tr></table>' : ''),
            },
        });

        expect((textarea.element as HTMLTextAreaElement).disabled).toBe(true);
        expect(wrapper.text()).toContain('Tabela reconhecida na cola');

        const discardButton = wrapper.findAll('button').find((b) => b.text().includes('Descartar formatação'));
        expect(discardButton).toBeTruthy();
        await discardButton?.trigger('click');

        expect((textarea.element as HTMLTextAreaElement).disabled).toBe(false);
        expect(wrapper.text()).not.toContain('Tabela reconhecida na cola');
    });
});

/**
 * §38 — "Rever tabela reconhecida": a complex source (here, an OCR'd image)
 * is shown the WHOLE recognised table — header, data, and what got
 * classified Group and dropped — BEFORE the per-student preview, and only
 * once the teacher continues does the per-student step actually appear.
 */
function structuralPreviewResponse() {
    return previewResponse([], [], {
        source_kind: 'image_upload',
        show_structural_step: true,
        structural: {
            headers: ['Nome', 'Medidas'],
            rows: [
                { number: 1, kind: 'header', cells: ['Nome', 'Medidas'] },
                { number: 2, kind: 'group', cells: ['Alunos com RTP', ''] },
                { number: 3, kind: 'data', cells: ['Ana Silva', 'MU'] },
            ],
        },
    });
}

describe('CharacterisationImportDialog — §38 structural review step', () => {
    it('shows the recognised table, including the dropped group row, before the per-student preview', async () => {
        extractTableFromImage.mockResolvedValue({
            rows: [],
            source_type: 'image_upload',
            source_filename: 'tabela.png',
            warnings: [],
            extraction_confidence: 1,
        });

        const wrapper = mountDialog();
        const input = wrapper.find('input[type="file"]');
        const image = new File(['fake'], 'tabela.png', { type: 'image/png' });

        Object.defineProperty(input.element, 'files', { value: [image], configurable: true });
        await input.trigger('change');
        await flushPromises();

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(structuralPreviewResponse()));
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        // The structural grid, not the per-student preview. Cell text lives
        // in editable <input> VALUES, not text nodes, so it is read from the
        // input elements rather than wrapper.text().
        expect(wrapper.text()).toContain('tal como foi reconhecida');
        const cellValues = wrapper.findAll('input[type="text"], input:not([type])').map((input) => (input.element as HTMLInputElement).value);
        expect(cellValues).toContain('Alunos com RTP');
        expect(wrapper.text()).toContain('Agrupamento — não entra como aluno.');
        expect(wrapper.findAll('button').some((b) => b.text().includes('Confirmar importação'))).toBe(false);
    });

    it('re-derives the per-student preview from a CORRECTED table, not the original guess', async () => {
        extractTableFromImage.mockResolvedValue({
            rows: [],
            source_type: 'image_upload',
            source_filename: 'tabela.png',
            warnings: [],
            extraction_confidence: 1,
        });

        const wrapper = mountDialog();
        const input = wrapper.find('input[type="file"]');
        const image = new File(['fake'], 'tabela.png', { type: 'image/png' });

        Object.defineProperty(input.element, 'files', { value: [image], configurable: true });
        await input.trigger('change');
        await flushPromises();

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(structuralPreviewResponse()));
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        // Continue past the structural step — the second POST should carry
        // the corrected table (still 3 rows: header + the untouched group +
        // the untouched data row, since nothing was edited in this test),
        // not the original OCR JSON, and the server's SECOND response (a
        // real per-student preview) must be what ends up on screen.
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([previewRow(1, 'Ana Silva')])),
        );

        const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continuar'));
        await continueButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Ana Silva');
        expect(wrapper.findAll('button').some((b) => b.text().includes('Confirmar importação'))).toBe(true);

        const secondCall = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[1];
        const secondBody = secondCall[1].body as FormData;
        const sentTable = JSON.parse(secondBody.get('extracted_table') as string);

        expect(sentTable.source_type).toBe('image_upload');
        expect(sentTable.rows).toHaveLength(3);
        expect(sentTable.rows.map((row: { kind: string }) => row.kind)).toEqual(['header', 'group', 'data']);
    });

    it('ignoring a row keeps it out of the corrected table sent to the server', async () => {
        extractTableFromImage.mockResolvedValue({
            rows: [],
            source_type: 'image_upload',
            source_filename: 'tabela.png',
            warnings: [],
            extraction_confidence: 1,
        });

        const wrapper = mountDialog();
        const input = wrapper.find('input[type="file"]');
        const image = new File(['fake'], 'tabela.png', { type: 'image/png' });

        Object.defineProperty(input.element, 'files', { value: [image], configurable: true });
        await input.trigger('change');
        await flushPromises();

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(structuralPreviewResponse()));
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        // Mark the group row "Ignorar linha" instead of leaving it Group.
        const kindSelects = wrapper.findAll('select').filter((select) => select.element.getAttribute('aria-label')?.startsWith('Tipo da linha'));
        expect(kindSelects.length).toBeGreaterThanOrEqual(2);
        await kindSelects[1].setValue('ignore');

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([previewRow(1, 'Ana Silva')])),
        );

        const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continuar'));
        await continueButton?.trigger('click');
        await flushPromises();

        const secondCall = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[1];
        const secondBody = secondCall[1].body as FormData;
        const sentTable = JSON.parse(secondBody.get('extracted_table') as string);

        expect(sentTable.rows).toHaveLength(2);
        expect(sentTable.rows.map((row: { kind: string }) => row.kind)).toEqual(['header', 'data']);
    });

    /**
     * Defect (2026-09-20 structural review report): a single-line <input>
     * cannot hold "\n" — the browser strips it the moment Vue sets the DOM
     * value, before the teacher ever touches the field. A multiline
     * observation must render on a <textarea> instead, so opening this step
     * alone never destroys the line break, and a cell the teacher never
     * edits is resubmitted byte-identical to what was extracted.
     */
    it('preserves a newline in a multiline cell through the structural step, touched or not', async () => {
        extractTableFromImage.mockResolvedValue({
            rows: [],
            source_type: 'image_upload',
            source_filename: 'tabela.png',
            warnings: [],
            extraction_confidence: 1,
        });

        const wrapper = mountDialog();
        const input = wrapper.find('input[type="file"]');
        const image = new File(['fake'], 'tabela.png', { type: 'image/png' });

        Object.defineProperty(input.element, 'files', { value: [image], configurable: true });
        await input.trigger('change');
        await flushPromises();

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(
                previewResponse([], [], {
                    source_kind: 'image_upload',
                    show_structural_step: true,
                    structural: {
                        headers: ['Nome', 'Observações'],
                        rows: [
                            { number: 1, kind: 'header', cells: ['Nome', 'Observações'] },
                            {
                                number: 2,
                                kind: 'data',
                                cells: ['Maria Santos', 'MU a) b) e)\nNecessita de apoio na organizacao.'],
                            },
                            { number: 3, kind: 'data', cells: ['Ana Silva', 'Sem observações'] },
                        ],
                    },
                }),
            ),
        );
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        // The multiline cell renders on a <textarea>, carrying the newline —
        // never an <input>, which cannot hold one at all.
        const textareas = wrapper.findAll('textarea');
        const multilineField = textareas.find((textarea) => (textarea.element as HTMLTextAreaElement).value.includes('\n'));
        expect(multilineField).toBeTruthy();
        expect((multilineField!.element as HTMLTextAreaElement).value).toBe(
            'MU a) b) e)\nNecessita de apoio na organizacao.',
        );

        // The single-line cell next to it stays on an ordinary <input>.
        const singleLineValues = wrapper
            .findAll('input[type="text"], input:not([type])')
            .map((el) => (el.element as HTMLInputElement).value);
        expect(singleLineValues).toContain('Sem observações');

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([previewRow(1, 'Maria Santos')])),
        );

        const continueButton = wrapper.findAll('button').find((b) => b.text().includes('Continuar'));
        await continueButton?.trigger('click');
        await flushPromises();

        // Resubmitted byte-identical — the cell was never touched.
        const secondCall = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[1];
        const secondBody = secondCall[1].body as FormData;
        const sentTable = JSON.parse(secondBody.get('extracted_table') as string);
        const sentDataRow = sentTable.rows.find((row: { kind: string }) => row.kind === 'data');

        expect(sentDataRow.cells[1].text).toBe('MU a) b) e)\nNecessita de apoio na organizacao.');
    });

    it('a plain paste never shows the structural step', async () => {
        const wrapper = mountDialog();
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse([previewRow(1, 'Aluno E1')])));

        await wrapper.find('#characterisation-paste').setValue('Aluno E1\tX');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Aluno E1');
        expect(wrapper.text()).not.toContain('tal como foi reconhecida');
    });
});

/**
 * §18/§19 — the per-student preview step must show EXTRACTION confidence
 * ("did I read this right?") as an indicator visually distinct from DOMAIN
 * confidence ("do I know what this means?", `confidence_label`) — never
 * merged into one badge — and an unresolved token with a suggestion must
 * offer an explicit, opt-in "Aceitar" control that leaves the original token
 * untouched until clicked.
 */
describe('CharacterisationImportDialog — §18/§19 extraction confidence and suggestions', () => {
    function rowWithLowConfidenceUnresolved() {
        const base = previewRow(1, 'Aluno F1');

        return {
            ...base,
            measures: [],
            unresolved: [
                {
                    raw_token: 'ACN5',
                    confidence: 'unrecognised',
                    confidence_label: 'Não reconhecido',
                    level: null,
                    level_label: null,
                    code: null,
                    code_label: null,
                    unresolved_annotations: [],
                    scope: 'institutional',
                    note: 'Sigla não reconhecida.',
                    storable: false,
                    family: null,
                    family_label: null,
                    has_structured_destination: false,
                    extraction_confidence: 0.4,
                    suggested_correction: { token: 'ACNS', expansion: 'Adaptação curricular não significativa' },
                },
            ],
        };
    }

    it('renders the domain-confidence label and the low-extraction-confidence warning as two separate indicators', async () => {
        const wrapper = mountDialog();
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([rowWithLowConfidenceUnresolved()])),
        );

        await wrapper.find('#characterisation-paste').setValue('Aluno F1\tACN5');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        // Domain confidence (already existed): the raw token next to its
        // CodeConfidence label.
        expect(wrapper.text()).toContain('ACN5');
        expect(wrapper.text()).toContain('Não reconhecido');

        // Extraction confidence (§18): a SEPARATE sentence, never folded into
        // the domain-confidence text above.
        expect(wrapper.text()).toContain('Confiança de leitura baixa');
    });

    it('offers the suggestion with an explicit accept control, without pre-applying it', async () => {
        const wrapper = mountDialog();
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(previewResponse([rowWithLowConfidenceUnresolved()])),
        );

        await wrapper.find('#characterisation-paste').setValue('Aluno F1\tACN5');
        const previewButton = wrapper.findAll('button').find((b) => b.text().includes('Pré-visualizar'));
        await previewButton?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('ACNS');
        expect(wrapper.text()).toContain('ACN5');

        const acceptButton = wrapper.findAll('button').find((b) => b.text().includes('Aceitar correção'));
        expect(acceptButton).toBeTruthy();

        // Not yet clicked: the original token is still what is shown, and no
        // extra request has gone out.
        expect((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls).toHaveLength(1);

        const resolvedRow = previewRow(1, 'Aluno F1');
        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(previewResponse([resolvedRow])));

        await acceptButton?.trigger('click');
        await flushPromises();

        // Accepting resubmitted the SAME source with `corrections` attached —
        // a second POST, not a client-side rewrite of the row in place.
        expect((fetch as unknown as ReturnType<typeof vi.fn>).mock.calls).toHaveLength(2);

        const [, secondInit] = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls[1];
        const secondBody = secondInit.body as FormData;
        expect(secondBody.get('corrections[ACN5]')).toBe('ACNS');

        // The server's fresh response (measure resolved, nothing unresolved)
        // is what ends up on screen.
        expect(wrapper.text()).not.toContain('Não reconhecido');
    });
});

function flushPromises(): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, 0));
}
