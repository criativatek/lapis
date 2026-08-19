<?php

namespace App\Services\Reporting\Writing;

/**
 * What the teacher is asking for (§5, §16).
 *
 * SIX, AND NOT MORE. A menu of twenty style options would be a menu nobody
 * reads, and every extra one is another sentence in the prompt that could be
 * read as permission to add something. These six are the ones a teacher can
 * actually tell apart when looking at a paragraph about their own class.
 *
 * NONE OF THEM AUTHORISES A NEW FACT, and each says so in its own words rather
 * than relying on the shared instruction to carry it alone. «Mais pedagógico» is
 * the one that invites invention — a model asked to be pedagogical will happily
 * explain WHY the results are what they are — so its instruction is the most
 * explicit of the six about having nothing to explain with.
 */
enum WritingMode: string
{
    case Clearer = 'clearer';
    case Concise = 'concise';
    case Pedagogical = 'pedagogical';
    case Formal = 'formal';
    case Fluent = 'fluent';
    case SameTone = 'same_tone';

    public function label(): string
    {
        return match ($this) {
            self::Clearer => __('Mais claro'),
            self::Concise => __('Mais conciso'),
            self::Pedagogical => __('Mais pedagógico'),
            self::Formal => __('Mais formal'),
            self::Fluent => __('Mais fluido'),
            self::SameTone => __('Melhorar sem alterar o tom'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Clearer => __('Frases mais simples, sem perder nada do que está dito.'),
            self::Concise => __('O mesmo conteúdo em menos palavras.'),
            self::Pedagogical => __('Linguagem mais próxima do discurso pedagógico, com os mesmos factos.'),
            self::Formal => __('Registo institucional, para documentos que saem da escola.'),
            self::Fluent => __('Melhores transições entre as frases.'),
            self::SameTone => __('Corrige o que está mal escrito e deixa o resto como está.'),
        };
    }

    /**
     * The one paragraph that differs between modes.
     *
     * Written as a constraint rather than a goal wherever possible: «não
     * acrescentes» is harder to over-interpret than «melhora».
     */
    public function instruction(): string
    {
        return match ($this) {
            self::Clearer => 'Simplifica a sintaxe: parte frases longas, resolve subordinadas encadeadas e substitui construções pesadas por outras mais diretas. Não simplifiques o conteúdo — nenhuma informação pode desaparecer para a frase ficar mais simples.',

            self::Concise => 'Reduz redundância: elimina repetições, perífrases e expressões de enchimento. Todos os factos têm de sobreviver ao corte. Se uma frase não puder ser encurtada sem perder informação, deixa-a como está.',

            // The dangerous one. A model told to be pedagogical will explain,
            // and there is nothing here to explain with.
            self::Pedagogical => 'Aproxima a formulação do discurso pedagógico corrente em Portugal, usando apenas os factos que já estão no texto. Não expliques resultados, não apresentes causas, não sugiras o que fazer a seguir e não acrescentes qualquer apreciação que não esteja escrita. Se o texto não diz porquê, o teu também não pode dizer.',

            self::Formal => 'Usa registo institucional sóbrio, adequado a um documento que sai da escola. Evita coloquialismos e ênfases. Não tornes o texto mais assertivo do que ele é: uma reserva continua a ser uma reserva.',

            self::Fluent => 'Melhora as transições entre frases e a coesão dos parágrafos. Podes reordenar frases dentro do mesmo parágrafo se isso melhorar a leitura, desde que nenhuma relação entre factos mude por causa disso.',

            self::SameTone => 'Corrige apenas o que está mal escrito: concordâncias, repetições evidentes, construções mecânicas e pontuação. Mantém o registo, o vocabulário e a extensão. Se uma frase já está bem escrita, devolve-a exatamente como está.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode): array => [
            'value' => $mode->value,
            'label' => $mode->label(),
            'description' => $mode->description(),
        ], self::cases());
    }
}
