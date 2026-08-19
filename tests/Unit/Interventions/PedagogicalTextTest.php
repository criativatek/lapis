<?php

namespace Tests\Unit\Interventions;

use App\Support\Interventions\PedagogicalText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule that separates what a teacher wrote from what the system left behind.
 *
 * BOTH DIRECTIONS MATTER EQUALLY. Letting «Legado sem dominio» through puts a
 * category nobody chose in front of a student's name; swallowing «Falta» throws
 * away something a teacher meant. The tests below pin down both edges, because a
 * rule this small is exactly the kind that gets widened later by somebody
 * chasing one more bad string.
 */
class PedagogicalTextTest extends TestCase
{
    #[Test]
    public function the_generated_legacy_label_is_not_content(): void
    {
        // The exact string on the row that prompted this fix.
        $this->assertNull(PedagogicalText::meaningful('Legado sem dominio'));
        // And its properly accented spelling, which is the same phrase.
        $this->assertNull(PedagogicalText::meaningful('Legado sem domínio'));
    }

    #[Test]
    public function the_other_known_machine_written_labels_are_not_content(): void
    {
        foreach (['Legado', 'Sem domínio', 'sem tipo', 'NULL', 'unknown', 'n/a'] as $label) {
            $this->assertNull(PedagogicalText::meaningful($label), "«{$label}» should not be shown.");
        }
    }

    #[Test]
    public function a_single_character_is_a_placeholder_and_not_an_observation(): void
    {
        // The `description` on the same row: one character typed to get past a
        // required field. No observation about a student is one letter long.
        $this->assertNull(PedagogicalText::meaningful('x'));
        $this->assertNull(PedagogicalText::meaningful('X'));
        $this->assertNull(PedagogicalText::meaningful(' - '));
        $this->assertNull(PedagogicalText::meaningful('...'));
        $this->assertNull(PedagogicalText::meaningful('??'));
    }

    #[Test]
    public function nothing_is_nothing(): void
    {
        $this->assertNull(PedagogicalText::meaningful(null));
        $this->assertNull(PedagogicalText::meaningful(''));
        $this->assertNull(PedagogicalText::meaningful('   '));
    }

    #[Test]
    public function what_a_teacher_wrote_survives_however_short(): void
    {
        // THE OTHER EDGE. A rule that hides short strings would eat these, and
        // they are somebody's words about a real class.
        foreach ([
            'Ok',
            'Falta',
            'Acompanhamento combinado com a diretora de turma',
            'Escrita orientada',
            'Apoio a x alunos do grupo',
        ] as $written) {
            $this->assertSame($written, PedagogicalText::meaningful($written));
        }
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed_and_nothing_else_is_touched(): void
    {
        $this->assertSame('Escrita orientada', PedagogicalText::meaningful('  Escrita orientada  '));
    }

    #[Test]
    public function a_real_phrase_that_merely_contains_a_forbidden_word_survives(): void
    {
        // «legado» inside a sentence is not the generated label. The list is
        // matched whole, never searched for (§6 — a specific rule, not a blind
        // filter).
        $this->assertSame(
            'Trabalho sobre o legado cultural da turma',
            PedagogicalText::meaningful('Trabalho sobre o legado cultural da turma'),
        );
    }
}
