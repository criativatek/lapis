/**
 * «Adicionar ao sumário» — a lógica pura por trás do botão de cada
 * acontecimento do dia em `lessons/Show.vue`. Nunca escreve nada: só decide
 * QUE TEXTO acrescentar ao sumário já escrito, e SE o botão faz sentido.
 *
 * O limite de 16000 caracteres é o mesmo do campo `content` do sumário
 * (`LessonSummaryRequest`) — testado aqui e não adivinhado no componente.
 */

export const LESSON_SUMMARY_MAX_LENGTH = 16000;

export type DayEventForSummary = {
    title: string;
    notes: string | null;
};

function singleLine(text: string): string {
    return text.trim().replace(/\s*\n+\s*/g, ' ');
}

/**
 * A linha que este acontecimento acrescentaria ao sumário: o título, seguido
 * de ` — ` e das notas quando existem — sempre uma única linha, com as
 * quebras de linha internas colapsadas em espaços. Também no título: o
 * formulário do Calendário não o deixa ter quebras, mas o pedido não as
 * recusa, e a regra «uma linha por acontecimento» é a que o «Já no sumário»
 * lê.
 */
export function summaryLineFor(event: DayEventForSummary): string {
    const title = singleLine(event.title);
    const notes = event.notes === null ? '' : singleLine(event.notes);

    if (notes === '') {
        return title;
    }

    return `${title} — ${notes}`;
}

/**
 * O sumário depois de lá acrescentada a linha deste acontecimento,
 * preservando o que já lá estava: se o conteúdo não é vazio e não acaba já
 * numa quebra de linha, insere uma antes da linha nova.
 */
export function appendEventToSummary(content: string, event: DayEventForSummary): string {
    const line = summaryLineFor(event);

    if (content === '') {
        return line;
    }

    return content.endsWith('\n') ? `${content}${line}` : `${content}\n${line}`;
}

/**
 * Se acrescentar este acontecimento já não faz sentido — porque a linha já
 * está no sumário, ou porque acrescentá-la ultrapassaria o limite — e porquê,
 * para o botão se desativar com a explicação certa em vez de falhar calado.
 *
 * «Já está» quer dizer UMA LINHA INTEIRA igual, e não o texto contido algures:
 * um acontecimento «Reunião» sem notas não pode ficar bloqueado só porque o
 * professor escreveu «Reunião de pais» noutra linha.
 */
export function summaryAppendBlockedReason(content: string, event: DayEventForSummary): 'already_present' | 'too_long' | null {
    const line = summaryLineFor(event);

    if (content.split(/\r?\n/).some((existing) => existing.trim() === line)) {
        return 'already_present';
    }

    if (appendEventToSummary(content, event).length > LESSON_SUMMARY_MAX_LENGTH) {
        return 'too_long';
    }

    return null;
}
