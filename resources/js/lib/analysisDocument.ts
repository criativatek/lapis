/**
 * A Análise da Avaliação em papel — a estrutura que o servidor envia (`BuildAnalysisDocument`).
 *
 * UMA ESTRUTURA, DOIS DESENHADORES. A pré-visualização (Vue) e o PDF (Blade) leem exatamente isto: blocos
 * simples cujo texto já vem escrito, por isso nenhum dos dois formata, arredonda ou reescreve nada.
 */

export type DocumentBlock =
    | { type: 'heading'; text: string }
    | { type: 'paragraph'; text: string; tone: 'plain' | 'muted' | 'caution' }
    | { type: 'keyvalues'; items: Array<{ label: string; value: string }> }
    | { type: 'list'; title: string | null; items: string[] }
    | { type: 'table'; columns: Array<{ key: string; label: string; align: 'left' | 'right' }>; rows: string[][] };

export type DocumentSection = { key: string; title: string; blocks: DocumentBlock[] };

export type AnalysisDocument = {
    title: string;
    subtitle: string;
    identity: {
        name: string;
        header_lines: string[];
        footer_note: string | null;
        is_configured: boolean;
        logo_url: string | null;
    };
    issued_at: string;
    issued_label: string;
    identification: Array<{ label: string; value: string }>;
    warnings: string[];
    sections: DocumentSection[];
    options: { sections: string[]; students: boolean };
    notice: string;
    is_empty: boolean;
};
