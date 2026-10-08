<?php

namespace App\Services\Lessons;

/**
 * O que a aplicação de uma sequência faz (ou deixa de fazer) a UMA aula do
 * horário. É o vocabulário comum da pré-visualização e da execução: o ecrã
 * mostra exatamente estes valores, e a execução só escreve nos que escrevem.
 */
enum LessonSequenceStepKind: string
{
    /** Aula vazia que recebe o próximo elemento. */
    case Fill = 'fill';

    /** Aula que a própria sequência preencheu e o professor não alterou: recebe o elemento (possivelmente editado). */
    case Update = 'update';

    /** Aula da sequência cujo conteúdo já é o do elemento: nada a escrever. */
    case Unchanged = 'unchanged';

    /** Aula da sequência que o professor alterou e que continua a ser a deste elemento: fica como está. */
    case Keep = 'keep';

    /** Aula já preparada, a substituir por escolha confirmada do professor. */
    case Replace = 'replace';

    /** Aula já preparada, preservada: o horário é saltado SEM consumir elemento. */
    case Preserve = 'preserve';

    /** Aula fechada (lecionada, professor ausente, atividade da turma): nunca é escrita. */
    case Closed = 'closed';

    /** Aula que a sequência preencheu e que, depois da reaplicação, já não tem elemento: o conteúdo sai. */
    case Release = 'release';

    /** O elemento cabe nesta aula, mas as opções escolhidas não deixam nada para copiar. */
    case NothingToCopy = 'nothing_to_copy';

    /** Consome um elemento da fila? */
    public function consumesItem(): bool
    {
        return match ($this) {
            self::Fill, self::Update, self::Unchanged, self::Keep, self::Replace, self::NothingToCopy => true,
            self::Preserve, self::Closed, self::Release => false,
        };
    }

    /** Escreve na aula? */
    public function writes(): bool
    {
        return in_array($this, [self::Fill, self::Update, self::Replace, self::Release], true);
    }
}
