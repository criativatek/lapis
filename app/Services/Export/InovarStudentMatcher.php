<?php

namespace App\Services\Export;

use App\Domain\Export\InovarMatchConfidence;
use App\Domain\Export\InovarTemplate;
use App\Domain\Export\InovarTemplateStudent;
use App\Models\Enrollment;
use App\Support\Export\PersonNameKey;
use Illuminate\Support\Collection;

/**
 * QUEM É CADA LINHA DA GRELHA DO INOVAR.
 *
 * Até aqui a resposta era uma só: o N.º de processo, e mais nada. Era seguro e
 * era demasiado estreito — uma turma cujos alunos foram escritos à mão não tem
 * N.º de processo nenhum no Lapispro, e a exportação inteira ficava bloqueada
 * por uma informação que o ficheiro da escola traz e que o Lapispro não precisa
 * de ter para saber de quem se trata.
 *
 * A REGRA PASSA A SER POR CONFIANÇA, e a ordem dela é a ordem do que se sabe:
 *
 *  1. N.º DE PROCESSO IGUAL DOS DOIS LADOS — a correspondência mais forte que
 *     existe, porque é um identificador e não uma descrição.
 *  2. O LAPISPRO NÃO TEM N.º DE PROCESSO — não bloqueia. O número do ficheiro é
 *     informação adicional da escola, não um requisito nosso; usa-se o nome.
 *  3. O NÚMERO DIVERGE MAS O NOME É FORTE — não se escolhe em silêncio. Fica
 *     marcado para o professor confirmar, com a divergência escrita por
 *     extenso.
 *  4. DOIS CANDIDATOS PLAUSÍVEIS — não se escolhe nenhum. O professor escolhe.
 *
 * NOME FORTE É PRIMEIRO E ÚLTIMO, e é isso que `PersonNameKey` decide. Nomes do
 * meio podem faltar ou estar abreviados; primeiro e último, não. E não há
 * aproximação nenhuma: «Martins» e «Martin» não correspondem (§26).
 *
 * DOIS ALUNOS NUNCA PODEM RECLAMAR A MESMA PESSOA. Depois de tudo resolvido,
 * uma última passagem verifica se alguma matrícula ficou atribuída a mais do
 * que uma linha da grelha — dois irmãos com o mesmo primeiro e último nome, uma
 * linha repetida — e nesse caso ambas as linhas passam a ambíguas. É a única
 * coisa que este serviço faz depois de decidir, e existe porque escrever a
 * mesma nota em duas linhas é escrever a nota errada numa delas.
 *
 * TUDO EM MEMÓRIA. Uma consulta pelas matrículas da turma, e o resto são
 * comparações de strings: nada aqui pergunta à base de dados por linha (§44).
 */
class InovarStudentMatcher
{
    /**
     * Uma decisão por linha da grelha.
     *
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  array<int, int>  $resolutions  linha da grelha → id da matrícula que o professor escolheu
     * @return list<array<string, mixed>>
     */
    public function match(InovarTemplate $template, Collection $enrollments, array $resolutions = []): array
    {
        $byProcessNumber = [];
        $byNameKey = [];
        $known = [];

        foreach ($enrollments as $enrollment) {
            $id = (int) $enrollment->getKey();
            $number = $this->normalizeNumber($enrollment->student->processNumber());
            $name = PersonNameKey::for(optional($enrollment->student->identity)->display_name);

            $known[$id] = [
                'enrollment' => $enrollment,
                'process_number' => $number,
                'name' => $name,
                'display_name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            ];

            if ($number !== null) {
                $byProcessNumber[$number][] = $id;
            }

            if ($name !== null) {
                $byNameKey[$name->key()][] = $id;
            }
        }

        $duplicatedInTemplate = $this->duplicatedProcessNumbers($template);

        $rows = [];

        foreach ($template->students as $line) {
            $rows[] = $this->decide($line, $known, $byProcessNumber, $byNameKey, $duplicatedInTemplate, $resolutions);
        }

        return $this->withoutSharedEnrollments($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $known
     * @param  array<string, list<int>>  $byProcessNumber
     * @param  array<string, list<int>>  $byNameKey
     * @param  array<string, true>  $duplicatedInTemplate
     * @param  array<int, int>  $resolutions
     * @return array<string, mixed>
     */
    protected function decide(
        InovarTemplateStudent $line,
        array $known,
        array $byProcessNumber,
        array $byNameKey,
        array $duplicatedInTemplate,
        array $resolutions,
    ): array {
        $number = $this->normalizeNumber($line->processNumber);
        $name = PersonNameKey::for($line->name);

        // O N.º de processo repetido dentro da própria grelha é um problema do
        // FICHEIRO, não da correspondência: nenhuma escolha do professor o
        // resolve, e por isso não vira uma pergunta — vira um erro a corrigir.
        if ($number !== null && isset($duplicatedInTemplate[$number])) {
            return $this->row($line, null, InovarMatchConfidence::None, [
                "O N.º de processo {$line->processNumber} aparece mais do que uma vez nesta grelha.",
            ], [], blocking: true);
        }

        $byNumber = $number === null ? [] : ($byProcessNumber[$number] ?? []);
        $byName = $name === null ? [] : ($byNameKey[$name->key()] ?? []);

        // O professor já respondeu a esta linha. A escolha dele vale sobre
        // tudo o que se segue — mas SÓ entre os candidatos que esta grelha
        // oferecia: um id vindo do browser que não esteja aqui não é uma
        // escolha, é um pedido para escrever noutra pessoa qualquer.
        $candidates = array_values(array_unique([...$byNumber, ...$byName]));
        $chosen = $resolutions[$line->row] ?? null;

        if ($chosen !== null && in_array($chosen, $candidates, true)) {
            return $this->row($line, $known[$chosen], InovarMatchConfidence::Strong, [], $candidates, chosenByTeacher: true);
        }

        // 1. O identificador coincide.
        if (count($byNumber) === 1) {
            $enrollment = $known[$byNumber[0]];
            $sameName = $name !== null && $enrollment['name'] !== null && $name->matches($enrollment['name']);

            if ($sameName || $name === null || $enrollment['name'] === null) {
                return $this->row($line, $enrollment, InovarMatchConfidence::Strong, [
                    "N.º de processo {$line->processNumber} coincide.",
                ], $candidates);
            }

            // O número diz uma pessoa e o nome diz outra. Não se escolhe: é
            // exatamente o caso em que uma escolha errada passa despercebida.
            return $this->row($line, $enrollment, InovarMatchConfidence::Probable, [
                "O N.º de processo {$line->processNumber} corresponde a «{$enrollment['display_name']}», mas o nome na grelha é «{$line->name}».",
            ], $candidates);
        }

        if (count($byNumber) > 1) {
            return $this->row($line, null, InovarMatchConfidence::Ambiguous, [
                "Há mais do que um aluno desta turma com o N.º de processo {$line->processNumber}.",
            ], $byNumber);
        }

        // 2/3/4. Sem correspondência pelo número — o nome é o que resta.
        if ($byName === []) {
            return $this->row($line, null, InovarMatchConfidence::None, [
                $name === null
                    ? 'Esta linha da grelha não tem nome nem N.º de processo que a identifiquem.'
                    : 'Nenhum aluno desta turma corresponde a este nome.',
            ], []);
        }

        if (count($byName) > 1) {
            $names = implode(', ', array_map(fn (int $id): string => $known[$id]['display_name'], $byName));

            return $this->row($line, null, InovarMatchConfidence::Ambiguous, [
                "Mais do que um aluno desta turma tem este primeiro e último nome: {$names}.",
            ], $byName);
        }

        $enrollment = $known[$byName[0]];

        // 2. O Lapispro não conhece o N.º de processo desta pessoa. O número do
        // ficheiro é informação da escola e não penaliza: o nome basta.
        if ($enrollment['process_number'] === null) {
            return $this->row($line, $enrollment, InovarMatchConfidence::Strong, [
                $number === null
                    ? 'O nome coincide. Nenhum dos lados tem N.º de processo.'
                    : "O nome coincide; o N.º de processo {$line->processNumber} não existe no Lapispro para este aluno.",
            ], $candidates);
        }

        // A grelha não traz número e o Lapispro tem um. Não há divergência —
        // há uma informação que o ficheiro não deu.
        if ($number === null) {
            return $this->row($line, $enrollment, InovarMatchConfidence::Strong, [
                'O nome coincide. Esta linha da grelha não tem N.º de processo.',
            ], $candidates);
        }

        // 3. Os dois lados têm número e são diferentes. O nome é forte, mas a
        // divergência tem de ser vista por uma pessoa antes de se escrever.
        return $this->row($line, $enrollment, InovarMatchConfidence::Probable, [
            "O nome coincide, mas o N.º de processo da grelha ({$line->processNumber}) difere do que o Lapispro tem para este aluno ({$enrollment['process_number']}).",
        ], $candidates);
    }

    /**
     * A mesma matrícula reclamada por duas linhas é sempre um erro numa delas.
     *
     * Acontece com irmãos que partilham primeiro e último nome, e com grelhas
     * onde alguém duplicou uma linha. Nenhuma das duas fica escolhida: as duas
     * passam a ambíguas, com a outra linha nomeada, e o professor desfaz o nó.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function withoutSharedEnrollments(array $rows): array
    {
        $claims = [];

        foreach ($rows as $index => $row) {
            if ($row['enrollment_id'] === null) {
                continue;
            }

            $claims[(int) $row['enrollment_id']][] = $index;
        }

        foreach ($claims as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }

            $lines = implode(', ', array_map(fn (int $index): string => (string) $rows[$index]['row'], $indexes));

            foreach ($indexes as $index) {
                // A escolha explícita do professor também cai aqui, e tem de
                // cair: duas escolhas que apontam à mesma pessoa continuam a ser
                // uma delas errada, e a autoridade dele não desfaz isso.
                $rows[$index]['confidence'] = InovarMatchConfidence::Ambiguous->value;
                $rows[$index]['enrollment_id'] = null;
                $rows[$index]['matched'] = false;
                $rows[$index]['reasons'][] = "O mesmo aluno é candidato às linhas {$lines} da grelha.";
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $enrollment
     * @param  list<string>  $reasons
     * @param  list<int>  $candidateIds
     * @return array<string, mixed>
     */
    protected function row(
        InovarTemplateStudent $line,
        ?array $enrollment,
        InovarMatchConfidence $confidence,
        array $reasons,
        array $candidateIds,
        bool $blocking = false,
        bool $chosenByTeacher = false,
    ): array {
        return [
            'row' => $line->row,
            'process_number' => $line->processNumber,
            'display_name' => $line->name,
            // A matrícula só viaja quando há de facto uma pessoa identificada:
            // uma linha ambígua não leva «o primeiro candidato» consigo.
            'enrollment_id' => $confidence === InovarMatchConfidence::Ambiguous || $enrollment === null
                ? null
                : (int) $enrollment['enrollment']->getKey(),
            // O nome do aluno do LAPISPRO, para o professor comparar com o da
            // grelha sem ter de abrir outra coisa.
            'lapis_name' => $enrollment === null ? null : $enrollment['display_name'],
            'lapis_process_number' => $enrollment === null ? null : $enrollment['process_number'],
            'confidence' => $confidence->value,
            'confidence_label' => $confidence->label(),
            // «Correspondido» é só o que se escreve sem perguntar. Uma
            // correspondência provável ainda não é uma correspondência.
            'matched' => $confidence->isAutomatic() && $enrollment !== null,
            'needs_teacher' => $confidence->needsTeacher(),
            'chosen_by_teacher' => $chosenByTeacher,
            'reasons' => $reasons,
            // Um problema do FICHEIRO, que nenhuma escolha resolve.
            'blocking' => $blocking,
            'candidates' => $candidateIds,
        ];
    }

    /**
     * @return array<string, true>
     */
    protected function duplicatedProcessNumbers(InovarTemplate $template): array
    {
        $seen = [];
        $duplicated = [];

        foreach ($template->students as $line) {
            $number = $this->normalizeNumber($line->processNumber);

            if ($number === null) {
                continue;
            }

            if (isset($seen[$number])) {
                $duplicated[$number] = true;
            }

            $seen[$number] = true;
        }

        return $duplicated;
    }

    /**
     * Trimmed, and nothing else. A leading zero is part of somebody's
     * identifier, not formatting to be tidied away.
     */
    protected function normalizeNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
