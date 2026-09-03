import type { QualitativeTone } from '@/lib/qualitativeTone';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';

/**
 * Cor semântica para pílulas de ESTADO — a resposta ao SUP-UEVAH4 («é tudo
 * muito neutro»), começada pelos ecrãs de instrumentos.
 *
 * A COR É REFORÇO, NUNCA A MENSAGEM. Cada pílula continua a mostrar o rótulo
 * por extenso; o tom só deixa distinguir «Concluído» de «Em correção» sem ler
 * — que era exactamente o que a captura daquele reporte mostrava a faltar:
 * três estados diferentes, três pílulas cinzentas iguais.
 *
 * MAPEADO PELO VALOR, NUNCA PELO RÓTULO. Os rótulos são pt-PT e mudam; os
 * valores são o contrato do enum. A mesma palavra tem a mesma semântica em
 * qualquer modelo («draft» é neutro em todo o lado, «completed» é verde em
 * todo o lado), por isso um mapa único serve a aplicação inteira e cresce uma
 * linha de cada vez.
 *
 * DESCONHECIDO → NEUTRO. Um estado novo aparece cinzento até alguém decidir o
 * tom — nunca inventa cor, e nada parte.
 */
const TONES: Record<string, QualitativeTone> = {
    // Instrumentos (InstrumentStatus)
    draft: 'neutral',
    prepared: 'blue',
    in_correction: 'amber',
    completed: 'green',
    published: 'green',
    cancelled: 'red',
    archived: 'neutral',

    // Turmas (ClassStatus) — `preparation` também é das aulas, com a mesma semântica.
    preparation: 'neutral',
    active: 'green',
    closed: 'neutral',

    // Aulas (LessonStatus)
    taught: 'green',

    // Relatórios (ReportStatus)
    finalized: 'green',
};

export function statusTone(status: string): QualitativeTone {
    return TONES[status] ?? 'neutral';
}

/** As classes da paleta da casa (`qualitativeToneClasses`), prontas a pôr num Badge. */
export function statusToneClasses(status: string): string {
    return qualitativeToneClasses[statusTone(status)];
}
