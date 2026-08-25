<?php

namespace App\Domain\Import\AcademicCalendar;

/**
 * Um período do ano — «1.º Semestre», «2.º Semestre» — tal como a tabela-resumo
 * do documento o descreve.
 *
 * O FIM É UMA LISTA E O INÍCIO NÃO, e a assimetria é o próprio assunto: os
 * documentos reais dão um só início para toda a gente e, no 2.º Semestre, tantos
 * fins quantas as coortes que a escola distingue. Guardar o fim como uma lista
 * de candidatos — mesmo quando só há um — é o que impede este parser de escolher
 * um deles por conta própria em qualquer ponto do caminho.
 */
final readonly class ParsedSemester
{
    /**
     * @param  string  $label  «1.º Semestre» — o texto do documento, que é também o `label` do AcademicPeriod
     * @param  string|null  $startsOn  «Y-m-d», ou null quando a coluna «Início» não trouxe data legível
     * @param  list<ParsedSemesterEnd>  $endCandidates
     * @param  int  $sequence  a ordem por que apareceu na tabela, a partir de 1
     */
    public function __construct(
        public string $label,
        public ?string $startsOn,
        public array $endCandidates,
        public string $rawStart,
        public int $sequence,
    ) {}

    /**
     * Há mais do que uma data de fim a concorrer ao mesmo campo?
     *
     * É esta pergunta — e não o número de coortes, nem o texto das células — que
     * decide se a pré-visualização exige uma escolha explícita ao professor.
     */
    public function isAmbiguous(): bool
    {
        return count($this->endCandidates) > 1;
    }

    /**
     * A data de fim quando — e SÓ quando — não há ambiguidade nenhuma.
     */
    public function unambiguousEnd(): ?string
    {
        return count($this->endCandidates) === 1 ? $this->endCandidates[0]->endsOn : null;
    }
}
