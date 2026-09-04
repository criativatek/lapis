import { describe, expect, it } from 'vitest';
import { qualitativeToneClasses } from '@/lib/qualitativeTone';
import { statusTone, statusToneClasses } from '@/lib/statusTone';

/**
 * A cor é reforço do estado, e o mapa é o contrato: se «completed» deixar de
 * ser verde ou «in_correction» deixar de ser âmbar, a pílula continua a
 * renderizar e ninguém repara — só o professor deixa de distinguir os estados
 * sem ler, que era o defeito reportado no SUP-UEVAH4.
 */
describe('statusTone', () => {
    it('separa os três estados que a captura do reporte mostrava iguais', () => {
        expect(statusTone('completed')).toBe('green');
        expect(statusTone('in_correction')).toBe('amber');
        expect(statusTone('prepared')).toBe('blue');
    });

    it('cancelado é vermelho; rascunho e arquivado ficam neutros', () => {
        expect(statusTone('cancelled')).toBe('red');
        expect(statusTone('draft')).toBe('neutral');
        expect(statusTone('archived')).toBe('neutral');
    });

    it('turmas e aulas: activo e dada verdes, preparação neutra', () => {
        expect(statusTone('active')).toBe('green');
        expect(statusTone('taught')).toBe('green');
        expect(statusTone('finalized')).toBe('green');
        expect(statusTone('preparation')).toBe('neutral');
        expect(statusTone('closed')).toBe('neutral');
    });

    it('a regra da casa nas classificações: proposto é cinza, confirmado é azul', () => {
        // «O sistema propõe (badge cinza), o professor atribui (badge azul)» —
        // .impeccable.md. Pintar o proposto seria o sistema a vestir-se de
        // decisão.
        expect(statusTone('proposed')).toBe('neutral');
        expect(statusTone('confirmed')).toBe('blue');
        expect(statusTone('published')).toBe('green');
    });

    it('suporte e importações: em curso âmbar, resolvido verde, falhado vermelho', () => {
        expect(statusTone('waiting_for_user')).toBe('amber');
        expect(statusTone('resolved')).toBe('green');
        expect(statusTone('failed')).toBe('red');
        expect(statusTone('imported')).toBe('green');
    });

    it('um estado desconhecido fica neutro — nunca inventa cor', () => {
        expect(statusTone('estado_que_ainda_nao_existe')).toBe('neutral');
    });

    it('as classes vêm da paleta da casa, não de uma segunda paleta', () => {
        expect(statusToneClasses('completed')).toBe(qualitativeToneClasses.green);
        expect(statusToneClasses('desconhecido')).toBe(qualitativeToneClasses.neutral);
    });
});
