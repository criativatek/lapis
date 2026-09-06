<?php

namespace App\Services\Progress\Ai;

use App\Support\Privacy\AiContext;
use App\Support\Privacy\Pseudonyms;

/**
 * The minimum a synthesis of one student's Evolução needs, expressed as an
 * `AiContext`.
 *
 * THE ALLOWLIST HERE IS TIGHTER THAN ANYWHERE ELSE IN THE PRODUCT, because
 * this is the only reading that is about a person. What travels is: the
 * subject, the year of schooling, the period, the student's own figures, the
 * per-domain figures, the deterministic FACT SENTENCES the panel already shows,
 * and structured counts and states for records and interventions. That is all.
 *
 * WHAT IS DELIBERATELY EXCLUDED, AND WHY EACH (§13 of the brief):
 *
 *   record descriptions      Free text a teacher wrote about an incident, a
 *                            difficulty or a conversation. This is where health,
 *                            diagnosis, family circumstance and social context
 *                            actually live in this application. Sending it would
 *                            put special-category data on the wire to a
 *                            general-purpose model in order to improve a
 *                            paragraph, which is not a trade this product makes.
 *                            The COUNTS and the KINDS go instead: «3 registos de
 *                            dificuldade» is what the reading needs and «o pai
 *                            está desempregado» is what it does not.
 *
 *   intervention objectives  Free text again, and specifically the field where a
 *                            teacher writes down what a measure is FOR — which
 *                            is frequently a health or a needs statement. The
 *                            state, the type, the domain and whether it is
 *                            awaiting review go instead.
 *
 *   disciplinary detail      The severity LABEL travels, as a category. What
 *                            happened does not.
 *
 *   every identifier         Name, class number, enrolment ulid, student ulid,
 *                            process number, photo URL, dates of enrolment.
 *
 * THE COST OF THAT IS REAL AND IS WRITTEN DOWN. A synthesis that cannot read
 * the teacher's own notes will sometimes miss the thing that matters most, and
 * will say so in CAUTELAS rather than guessing. Lowering the barrier to fix
 * that would mean sending a child's health information to an engine, which is
 * the trade this file exists to refuse. See the AI-complete report, «dados
 * sensíveis deliberadamente excluídos».
 *
 * ONE PERSON, ONE PSEUDONYM. The roster has a single name in it — this
 * student's — so every mention of it in a domain name, a fact sentence or an
 * intervention label becomes «Aluno A» at the moment it is added. The pseudonym
 * is not stable between requests, on purpose: two syntheses of the same student
 * on two days are not linkable into a profile by whoever receives them.
 */
final class FollowupContext
{
    /** How many per-domain rows travel. A profile with more domains than this is unusual. */
    public const MAX_DOMAIN_ROWS = 12;

    /** How many interventions travel, most recent first. */
    public const MAX_INTERVENTION_ROWS = 8;

    /**
     * @param  array<string, mixed>  $progress  exactly what `BuildStudentProgress::for()` returned.
     * @param  list<array<string, mixed>>  $factualAlerts  the panel's own deterministic attention sentences.
     * @param  list<array<string, mixed>>  $strengths  the panel's own deterministic progress sentences.
     * @param  string  $subject  read off the class model, never off a label in the payload.
     * @param  string|null  $gradeLevel  the year of schooling, when the class records one.
     */
    public static function build(
        array $progress,
        array $factualAlerts,
        array $strengths,
        string $subject,
        ?string $gradeLevel,
    ): AiContext {
        $studentName = self::studentName($progress);

        // OS NOMES VOLTAM CURTOS — primeiro e último (§40). Uma análise que
        // enumere seis alunos pelo nome completo é ilegível, e primeiro e último
        // nome é como uma pessoa chama outra numa reunião de conselho de turma.
        // Nada disto muda o que SAI daqui: o que viaja continua a ser «Aluno A».
        $context = AiContext::about(Pseudonyms::of($studentName === null ? [] : [$studentName])->restoringShortNames());

        /** @var array<string, mixed> $headline */
        $headline = is_array($progress['headline'] ?? null) ? $progress['headline'] : [];
        /** @var array<string, mixed> $reading */
        $reading = is_array($progress['reading'] ?? null) ? $progress['reading'] : [];
        /** @var array<string, mixed>|null $selectedPeriod */
        $selectedPeriod = is_array($progress['selectedPeriod'] ?? null) ? $progress['selectedPeriod'] : null;

        $context
            ->add('Disciplina', $subject)
            ->add('Ano de escolaridade', $gradeLevel)
            ->add('Período em análise', $selectedPeriod === null ? null : self::scalar($selectedPeriod['label'] ?? null))
            ->add('Leitura principal', self::scalar($reading['label'] ?? null))
            // Late entry is a FACT about coverage, not about the student: an
            // instrument applied before somebody arrived never enters their
            // denominator (§11.4), and a reading that does not know this will
            // describe a short record as a thin one.
            ->add('Entrou na turma depois do início', ($progress['student']['is_late_entry'] ?? false) === true ? 'sim' : 'não');

        self::addHeadline($context, $headline);
        self::addSinceLast($context, $progress['sinceLast'] ?? null);
        self::addClassComparison($context, $progress['classComparison'] ?? null);
        self::addSelfAssessment($context, $progress['selfAssessments'] ?? null);
        self::addDomains($context, $progress['domains'] ?? null);
        self::addRecords($context, $progress['records'] ?? null);
        self::addInterventions($context, $progress['interventions'] ?? null);
        self::addSentences($context, 'Facto assinalado pelo sistema', $factualAlerts);
        self::addSentences($context, 'Ponto positivo assinalado pelo sistema', $strengths);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $headline
     */
    private static function addHeadline(AiContext $context, array $headline): void
    {
        /** @var array<string, mixed>|null $band */
        $band = is_array($headline['band'] ?? null) ? $headline['band'] : null;
        /** @var array<string, mixed>|null $coverage */
        $coverage = is_array($headline['coverage'] ?? null) ? $headline['coverage'] : null;

        $context
            ->add('Resultado atual', self::figure($headline['value'] ?? null))
            ->add('Nível atual', $band === null ? null : self::nullableScalar($band['label'] ?? null))
            // The coverage KEY, not its sentence: the sentence names instruments
            // a teacher titled, and the key is what the reading needs.
            ->add('Cobertura do resultado', $coverage === null ? null : self::nullableScalar($coverage['key'] ?? null))
            ->add('Classificação decidida pelo professor', self::classification($headline['classification'] ?? null));
    }

    private static function addSinceLast(AiContext $context, mixed $sinceLast): void
    {
        if (! is_array($sinceLast)) {
            $context->add('Comparação com o período anterior', 'não existe período anterior para comparar');

            return;
        }

        $context
            ->add('Período anterior', self::nullableScalar($sinceLast['from_label'] ?? null))
            ->add('Resultado no período anterior', self::figure($sinceLast['from'] ?? null))
            ->add('Resultado no período atual', self::figure($sinceLast['to'] ?? null));
    }

    /**
     * How this student sits against the class.
     *
     * THE CLASS FIGURE AND THE STUDENT'S, BOTH ALREADY CALCULATED. The
     * difference between them is in the payload too and travels as given — it
     * is `BuildStudentProgress`'s own subtraction, not one made here.
     */
    private static function addClassComparison(AiContext $context, mixed $comparison): void
    {
        if (! is_array($comparison)) {
            return;
        }

        $context
            ->add('Média da turma', self::figure($comparison['class'] ?? null))
            ->add('Alunos da turma com resultado', self::nullableScalar($comparison['students_with_result'] ?? null))
            ->add('Diferença face à turma', self::figure($comparison['difference'] ?? null));
    }

    /**
     * What the student said about themselves, per period.
     *
     * THE DIRECTION, NEVER THE GAP IN POINTS (§11). «acima» is an observation
     * about two records; «12 pontos acima» invites a model to characterise how
     * wrong a child is about their own work.
     */
    private static function addSelfAssessment(AiContext $context, mixed $selfAssessments): void
    {
        if (! is_array($selfAssessments)) {
            return;
        }

        $lines = [];

        foreach ($selfAssessments as $row) {
            if (! is_array($row)) {
                continue;
            }

            $self = self::level($row['self_assessment'] ?? null);

            if ($self === null) {
                continue;
            }

            $line = self::scalar($row['period_label'] ?? null).': o aluno indicou '.$self;

            $comparison = is_array($row['comparison'] ?? null)
                ? self::nullableScalar($row['comparison']['direction'] ?? null)
                : null;

            if ($comparison !== null) {
                $line .= ' ('.$comparison.' da evidência disponível)';
            }

            $lines[] = $line;
        }

        if ($lines !== []) {
            $context->addList('Autoavaliação registada', $lines);
        }
    }

    private static function addDomains(AiContext $context, mixed $domains): void
    {
        if (! is_array($domains) || ! is_array($domains['rows'] ?? null)) {
            return;
        }

        $lines = [];

        foreach (array_slice($domains['rows'], 0, self::MAX_DOMAIN_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            // The domain's NAME, which a teacher wrote and which is what makes
            // the reading legible — and which, being teacher-authored, is
            // exactly the kind of field that could contain somebody's name. It
            // goes through `add()` like everything else.
            $line = self::scalar($row['name'] ?? null)
                .': '.(self::figure($row['weighted_average'] ?? null) ?? 'sem resultado');

            $mention = self::nullableScalar($row['mention'] ?? null);

            if ($mention !== null) {
                $line .= ', menção '.$mention;
            }

            $movement = is_array($row['evolution'] ?? null)
                ? self::nullableScalar($row['evolution']['direction'] ?? null)
                : null;

            if ($movement !== null) {
                $line .= ', evolução '.$movement;
            }

            $coverage = is_array($row['coverage'] ?? null)
                ? self::nullableScalar($row['coverage']['key'] ?? null)
                : null;

            if ($coverage !== null && $coverage !== 'complete') {
                $line .= ', cobertura '.$coverage;
            }

            $lines[] = $line;
        }

        if ($lines !== []) {
            $context->addList('Por domínio', $lines);
        }
    }

    /**
     * Records as COUNTS BY KIND, never as text.
     *
     * THE SINGLE MOST IMPORTANT EXCLUSION IN THIS FILE. `EvidenceRecord`'s
     * `description` is where a teacher writes what actually happened, and that
     * is where health, diagnosis, behaviour, family and social context live in
     * this application. The panel's own summary already counts them by kind;
     * that summary is what travels.
     */
    private static function addRecords(AiContext $context, mixed $records): void
    {
        if (! is_array($records)) {
            return;
        }

        $context->add('Total de registos', self::nullableScalar($records['total'] ?? null));

        // `kinds`, which is what `BuildStudentProgress::records()` calls the
        // per-kind tally — one entry per kind this student actually has, from
        // the system's own enum. `rows` sits beside it and is deliberately not
        // read: that is where `description` lives.
        if (! is_array($records['kinds'] ?? null)) {
            return;
        }

        $lines = [];

        foreach ($records['kinds'] as $count) {
            if (! is_array($count)) {
                continue;
            }

            $label = self::nullableScalar($count['label'] ?? null);

            if ($label === null) {
                continue;
            }

            $lines[] = $label.': '.self::scalar($count['count'] ?? null);
        }

        if ($lines !== []) {
            $context->addList('Registos por tipo', $lines);
        }
    }

    /**
     * Interventions as STATES AND CATEGORIES, never as objectives.
     *
     * `objective`, `description` and `motive` are free text a teacher wrote
     * about why a measure exists, and a measure exists for reasons that are
     * frequently a needs or a health statement. What a reading needs is that a
     * measure is in place, in which domain, since when, and what its recorded
     * effectiveness is — all of which are structured fields.
     */
    private static function addInterventions(AiContext $context, mixed $interventions): void
    {
        if (! is_array($interventions)) {
            return;
        }

        $context
            ->add('Intervenções registadas', self::nullableScalar($interventions['total'] ?? null))
            ->add('Intervenções à espera de revisão', self::nullableScalar($interventions['needing_review'] ?? null));

        if (! is_array($interventions['rows'] ?? null)) {
            return;
        }

        $lines = [];

        foreach (array_slice($interventions['rows'], 0, self::MAX_INTERVENTION_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parts = [];

            $type = self::nullableScalar($row['type'] ?? null);
            $domain = self::nullableScalar($row['domain'] ?? null);
            $status = self::nullableScalar($row['status'] ?? null);
            $effectiveness = self::nullableScalar($row['effectiveness'] ?? null);
            $startedOn = self::nullableScalar($row['started_on'] ?? null);
            $followups = self::nullableScalar($row['followup_count'] ?? null);

            $parts[] = 'tipo '.($type ?? 'não indicado');
            $parts[] = 'domínio '.($domain ?? 'não específico');
            $parts[] = 'estado '.($status ?? 'não indicado');

            if ($startedOn !== null) {
                $parts[] = 'início '.$startedOn;
            }

            if ($effectiveness !== null) {
                $parts[] = 'eficácia registada '.$effectiveness;
            }

            if ($followups !== null) {
                $parts[] = $followups.' revisão(ões)';
            }

            $lines[] = implode(', ', $parts);
        }

        if ($lines !== []) {
            $context->addList('Intervenções (categorias e estados, sem texto livre)', $lines);
        }
    }

    /**
     * The panel's own deterministic sentences, forwarded as facts.
     *
     * COMPOSED BY THIS APPLICATION, NOT BY A TEACHER. `BuildStudentFactualAlerts`
     * and `BuildStudentStrengths` build every one of these from counts and
     * states, using their own fixed wording — so forwarding them sends no free
     * text, and sends exactly the facts the teacher is looking at above the
     * panel this reading appears in. That is what makes «distinguir facto de
     * interpretação» real: the model is handed the facts, and what it adds is
     * labelled as the addition.
     *
     * @param  list<array<string, mixed>>  $sentences
     */
    private static function addSentences(AiContext $context, string $label, array $sentences): void
    {
        $lines = [];

        foreach ($sentences as $sentence) {
            $text = self::nullableScalar($sentence['sentence'] ?? null);

            if ($text !== null) {
                $lines[] = $text;
            }
        }

        if ($lines !== []) {
            $context->addList($label, $lines);
        }
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private static function studentName(array $progress): ?string
    {
        $student = is_array($progress['student'] ?? null) ? $progress['student'] : [];
        $name = $student['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    private static function classification(mixed $classification): ?string
    {
        if (! is_array($classification)) {
            return null;
        }

        $final = $classification['final'] ?? null;

        if (is_array($final)) {
            return self::nullableScalar($final['label'] ?? null);
        }

        return self::nullableScalar($final);
    }

    private static function level(mixed $value): ?string
    {
        if (is_array($value)) {
            return self::nullableScalar($value['label'] ?? null) ?? self::nullableScalar($value['level'] ?? null);
        }

        return self::nullableScalar($value);
    }

    /**
     * A figure at the precision the application displays.
     *
     * NOT A CALCULATION — A FORMAT. The per-student figures on this panel come
     * off the calculation outcome unrounded, and sending eight decimals is
     * harmful twice: the model cites a number no screen has shown, and a run of
     * six or more digits is what `AiPayloadSanitizer` removes — so the model
     * would receive «89.[número removido]».
     */
    private static function figure(mixed $value): ?string
    {
        $plain = self::nullableScalar($value);

        if ($plain === null || ! is_numeric($plain)) {
            return $plain;
        }

        return number_format((float) $plain, 1, '.', '');
    }

    private static function scalar(mixed $value): string
    {
        return self::nullableScalar($value) ?? 'não disponível';
    }

    private static function nullableScalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_scalar($value) || is_bool($value)) {
            return null;
        }

        return (string) $value;
    }
}
