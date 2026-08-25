<?php

namespace App\Services\Import\AcademicCalendar;

use RuntimeException;

/**
 * O ficheiro não pôde ser lido como um calendário escolar — e a razão é uma
 * FRASE PARA O PROFESSOR, nunca um rastreio de pilha.
 *
 * Todas estas são situações ESPERADAS: uma folha de cálculo que é outra coisa
 * qualquer, um ficheiro corrompido, um pacote .xlsx com conteúdo ativo lá dentro.
 * O controlador apanha-as e devolve-as como erro de validação no campo do
 * ficheiro, exatamente como TimetablePdfException já é tratada na importação de
 * horários — o professor lê o que correu mal e escolhe outro ficheiro.
 */
class AcademicCalendarFileException extends RuntimeException
{
    public static function unsafePackage(): self
    {
        return new self(__('Este ficheiro não pôde ser aberto em segurança. Exporte de novo o calendário a partir da folha de cálculo original, sem macros nem ligações externas.'));
    }

    public static function unreadable(): self
    {
        return new self(__('Não foi possível ler esta folha de cálculo. Confirme que o ficheiro não está danificado e que foi guardado no formato .xlsx.'));
    }

    /**
     * A grelha dos meses é o que torna um calendário escolar reconhecível — uma
     * linha com os nomes dos meses lado a lado. Sem ela, o ficheiro pode ser uma
     * folha de cálculo perfeitamente válida e não ser um calendário.
     */
    public static function notACalendar(): self
    {
        return new self(__('Não foi encontrado nenhum calendário escolar neste ficheiro. É esperada uma folha com os meses do ano letivo lado a lado, como no calendário publicado pela escola.'));
    }

    public static function unknownAcademicYear(): self
    {
        return new self(__('Não foi possível determinar a que ano letivo este calendário se refere. Selecione um ano letivo antes de importar.'));
    }

    public static function nothingFound(): self
    {
        return new self(__('O calendário foi lido, mas não trouxe nenhuma data aproveitável — nem períodos, nem feriados, nem interrupções letivas.'));
    }
}
