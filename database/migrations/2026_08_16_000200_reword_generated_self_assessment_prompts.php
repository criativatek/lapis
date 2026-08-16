<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Puts the self-assessment into the first person.
 *
 * «Como te avalias em Leitura?» is the app talking to the student. What the
 * student is filling in is their own reading of the period, and it should read
 * like one: «Como avalio o meu desempenho em Leitura?».
 *
 * WHAT THIS IS ALLOWED TO TOUCH is narrow. Every replacement below is a string
 * SelfAssessmentTemplateProvider wrote itself, and a row is only rewritten when
 * its prompt is that exact sentence — for a domain question, rebuilt from the
 * domain the row itself points at. A prompt a teacher edited matches none of
 * them and is left exactly as they wrote it. Nothing else changes: not the
 * role, not the domain, not one answer.
 *
 * The old wording came in two generations, and the earlier one was written when
 * the provider still asked every class on the 1–5 scale — which is why it can be
 * reworded into the level phrasing without consulting anything.
 */
return new class extends Migration
{
    protected const DOMAIN_BEFORE = 'Como te avalias em %s?';

    protected const DOMAIN_AFTER = 'Como avalio o meu desempenho em %s?';

    public function up(): void
    {
        $this->rewordDomainQuestions(self::DOMAIN_BEFORE, self::DOMAIN_AFTER);
        $this->rewordRoleQuestions($this->intoFirstPerson());
    }

    /**
     * Restores the wording the provider used before, per role and per scale.
     *
     * Two earlier variants collapse into one sentence going forward, so this
     * puts back the canonical one rather than whichever of the two a given row
     * happened to carry — a prior valid state, not a byte-for-byte rewind.
     */
    public function down(): void
    {
        $this->rewordDomainQuestions(self::DOMAIN_AFTER, self::DOMAIN_BEFORE);
        $this->rewordRoleQuestions($this->backToSecondPerson());
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function intoFirstPerson(): array
    {
        return [
            'global' => [
                'No conjunto, que nível propões para a tua avaliação neste período?' => 'Nível que proponho para a minha avaliação neste período',
                'Que classificação propões para a tua avaliação neste período?' => 'Classificação que proponho para a minha avaliação neste período',
                'Como avalias globalmente o teu desempenho neste período?' => 'Nível que proponho para a minha avaliação neste período',
            ],
            'rationale' => [
                'Porque propões este nível?' => 'Porque proponho este nível?',
                'Porque propões esta classificação?' => 'Porque proponho esta classificação?',
                'Porque te avalias assim?' => 'Porque me avalio assim?',
                'Porque escolheste esta autoavaliação?' => 'Porque proponho este nível?',
            ],
            'improvement' => [
                'O que precisas de melhorar no próximo período?' => 'O que preciso de melhorar no próximo período?',
            ],
        ];
        // `liked` and `struggled` were already written in the first person and
        // are not touched at all.
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected function backToSecondPerson(): array
    {
        return [
            'global' => [
                'Nível que proponho para a minha avaliação neste período' => 'No conjunto, que nível propões para a tua avaliação neste período?',
                'Classificação que proponho para a minha avaliação neste período' => 'Que classificação propões para a tua avaliação neste período?',
            ],
            'rationale' => [
                'Porque proponho este nível?' => 'Porque propões este nível?',
                'Porque proponho esta classificação?' => 'Porque propões esta classificação?',
                'Porque me avalio assim?' => 'Porque te avalias assim?',
            ],
            'improvement' => [
                'O que preciso de melhorar no próximo período?' => 'O que precisas de melhorar no próximo período?',
            ],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $rewordings
     */
    protected function rewordRoleQuestions(array $rewordings): void
    {
        foreach ($rewordings as $role => $replacements) {
            foreach ($replacements as $before => $after) {
                DB::table('self_assessment_questions')
                    ->where('role', $role)
                    ->where('prompt', $before)
                    ->update(['prompt' => $after]);
            }
        }
    }

    /**
     * The domain questions, each checked against the sentence the provider
     * would have written for THAT domain — never against a pattern that would
     * also swallow a question a teacher rewrote about the same subject.
     */
    protected function rewordDomainQuestions(string $before, string $after): void
    {
        $names = DB::table('domains')->pluck('name', 'id');

        $questions = DB::table('self_assessment_questions')
            ->whereNotNull('domain_id')
            ->get(['id', 'domain_id', 'prompt']);

        foreach ($questions as $question) {
            $name = $names[$question->domain_id] ?? null;

            if ($name === null || $question->prompt !== sprintf($before, $name)) {
                continue;
            }

            DB::table('self_assessment_questions')
                ->where('id', $question->id)
                ->update(['prompt' => sprintf($after, $name)]);
        }
    }
};
