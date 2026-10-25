/**
 * Retificação de classificações publicadas: o que o servidor manda e como se lê.
 *
 * O cliente não decide nada sobre quem pode retificar — só desenha o que vem em
 * `rectification` (a mesma estrutura em Classificações e em Resultados) e, ao
 * abrir o diálogo ou o histórico, o que o endpoint de leitura responde.
 */

/** Por linha com classificação, vindo do servidor (RectificationPayload). */
export type RectificationState = {
    allowed: boolean;
    needs_confirmation: boolean;
    /** Dito pelo servidor; é a única fonte de qualquer palavra sobre quem pode. */
    blocked_reason: string | null;
    lock_version: number;
    /** Quantas retificações a célula já teve. */
    count: number;
};

export type RectificationReasonOption = {
    value: string;
    label: string;
    requires_note: boolean;
};

export type RectificationHistoryEntry = {
    ulid: string;
    status: string;
    is_live: boolean;
    final: { code: string; label: string | null; scale_level_id: number | null; is_percentage?: boolean; scale_name?: string | null } | null;
    decided_by: string | null;
    decided_at: string | null;
    reason: string | null;
    note: string | null;
    rectified: boolean;
};

export type RectificationExportNotice = { at: string; adapter: string; moment_label: string };

export type RectificationDetails = {
    history: RectificationHistoryEntry[];
    notice: { generic: string; export: RectificationExportNotice | null };
};

/** A escala em que a turma decide — a mesma que o ecrã usa em «Alterar». */
export type RectificationScale = {
    label: string;
    classifies_by_level: boolean;
    levels: { id: number; code: string; label: string }[];
    min_value: string | null;
    max_value: string | null;
};

const dateTimeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'Europe/Lisbon',
});

/** «25/10/2026, 14:30» no fuso da aplicação. */
export function rectificationDateTime(iso: string | null): string {
    return iso === null ? '—' : dateTimeFormatter.format(new Date(iso));
}

export function rectificationDate(iso: string): string {
    return dateFormatter.format(new Date(iso));
}

/** O único código com nome próprio conhecido; os outros dizem-se como vêm. */
export function adapterLabel(adapter: string): string {
    return adapter === 'inovar' ? 'Inovar' : adapter;
}

export function exportNoticeText(notice: RectificationExportNotice): string {
    return `Exportada para «${adapterLabel(notice.adapter)}» em ${rectificationDate(notice.at)} (${notice.moment_label}). Atualize esse registo.`;
}

/** «4 — Bom», «15» ou «65%», como as páginas lêem uma decisão. */
export function entryValueText(final: RectificationHistoryEntry['final']): string {
    if (final === null) {
        return '—';
    }

    if (final.label) {
        return `${final.code} — ${final.label}`;
    }

    const number = String(Number(final.code)).replace('.', ',');

    return final.is_percentage === true ? `${number}%` : number;
}

export async function loadRectification(url: string): Promise<RectificationDetails> {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (!response.ok) {
        throw new Error(`rectification ${response.status}`);
    }

    return (await response.json()) as RectificationDetails;
}
