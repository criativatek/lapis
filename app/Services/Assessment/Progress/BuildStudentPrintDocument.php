<?php

namespace App\Services\Assessment\Progress;

/**
 * The print document's shape: a title and an ordered, capability-filtered
 * list of sections — never a second entitlement system (§ print brief).
 *
 * THIS CLASS DECIDES *WHAT* IS IN THE DOCUMENT; Print.vue decides *HOW* each
 * section looks, reading the very same `progress` / `factualAlerts` /
 * `strengths` / `pro` arrays the panel (Show.vue) already renders from.
 * Nothing here is a new source of truth for a figure, a sentence or a
 * capability answer — `$allowsAdvancedAnalytics` is the ONE fact this class
 * is handed, resolved once by the controller through `Entitlements`, exactly
 * as `student()` already resolves it for the panel. Every analytical section
 * below is filtered from that single boolean and from `$pro` actually having
 * content — never from a plan name.
 *
 * A CAPABILITY CHANGE NEEDS NO EDIT HERE OR IN Print.vue. Grant or withdraw
 * `advanced_analytics` — plan, trial voucher, in-force override, downgrade —
 * and the very same code path already reflects it on the next print, because
 * the boolean itself moved, not a hardcoded plan check (§9, §20, §21 of the
 * print brief).
 *
 * NOTHING LOCKED ON PAPER (§4). A section absent from the returned list is
 * absent from the document — never rendered greyed out, never a placeholder,
 * never an upsell. `sections` already IS the filtered, renderable list:
 * Print.vue asks only "is this key present?", never "is this capability
 * present?" a second time, and never shows a section with nothing real to
 * say.
 */
class BuildStudentPrintDocument
{
    /**
     * @param  array<string, mixed>  $progress  BuildStudentProgress::for()
     * @param  list<array{key: string, sentence: string, count: int}>  $factualAlerts  BuildStudentFactualAlerts::for()
     * @param  list<array{key: string, sentence: string}>  $strengths  BuildStudentStrengths::for()
     * @param  array<string, mixed>|null  $pro  BuildStudentInsights::for(), or null when not computed
     * @return array{title: string, sections: list<array{key: string, label: string, capability: string}>}
     */
    public function for(array $progress, array $factualAlerts, array $strengths, ?array $pro, bool $allowsAdvancedAnalytics): array
    {
        $hasMoments = collect((array) ($progress['moments'] ?? []))
            ->contains(fn (array $moment): bool => $moment['value'] !== null);

        $sections = array_values(array_filter([
            // NO «identificação» SECTION. Who the student is, which turma,
            // which disciplina, which ano letivo and which período are the
            // document's own header — printing them again as the first card
            // spent half a page repeating what the reader had just read.
            // The document starts where the information starts.
            $this->section('current_situation', 'Situação atual', 'student_progress', true),
            $this->section('domains', 'Resultados por domínio', 'student_progress', ($progress['domains']['rows'] ?? []) !== []),
            $this->section('evolution', 'Como evoluiu', 'student_progress', $hasMoments),
            $this->section('recent_assessments', 'Últimas avaliações', 'student_progress', ($progress['recentInstruments'] ?? []) !== []),
            $this->section('self_assessments', 'Autoavaliação', 'student_progress', ($progress['selfAssessments'] ?? []) !== []),
            $this->section('academic_records', 'Classificações atribuídas', 'student_progress', ($progress['classifications'] ?? []) !== []),
            $this->section('records', 'Registos', 'student_progress', (int) ($progress['records']['total'] ?? 0) > 0),
            // «Atenção» and «Pontos fortes» ARE ANALYTICAL SECTIONS, and their
            // capability says so. §5 of the Matriz Mestre lists what the Base
            // ficha may contain — identificação, resultados, domínios,
            // classificações, avaliações recentes, autoavaliações, registos,
            // estratégias/medidas — and neither of these is on it; both appear
            // instead in the list of what the PRO síntese adds. They stay
            // physically here, above the rest of the analytical block, because
            // that is where they read on the page; what changed is the
            // capability they are labelled with and, upstream, the fact that
            // StudentProgressController does not compute them at all without
            // it — so both lists arrive empty on Base and drop out here.
            $this->section('attention_factual', 'Atenção', 'advanced_analytics', $factualAlerts !== []),
            $this->section('strengths_factual', 'Pontos fortes', 'advanced_analytics', $strengths !== []),
            $this->section('strategies', 'Estratégias e Medidas', 'student_progress', (int) ($progress['interventions']['total'] ?? 0) > 0),

            // Analytical — every entry below is null whenever the capability
            // is absent, which drops it from the list entirely rather than
            // handing Print.vue a key it would have to hide itself (§4, §15).
            ! $allowsAdvancedAnalytics || $pro === null ? null
                : $this->section('attention_analytical', 'Atenção — leitura analítica', 'advanced_analytics', ($pro['analyticalAlerts'] ?? []) !== []),
            ! $allowsAdvancedAnalytics || $pro === null ? null
                : $this->section('positive_signals', 'Sinais positivos', 'advanced_analytics', ($pro['positiveSignals'] ?? []) !== []),
            ! $allowsAdvancedAnalytics || $pro === null ? null
                : $this->section('estado360', 'Estado 360º', 'advanced_analytics', true),
            ! $allowsAdvancedAnalytics || $pro === null ? null
                : $this->section('what_changed', 'O que mudou', 'advanced_analytics', true),
            ! $allowsAdvancedAnalytics || $pro === null ? null
                : $this->section('potentialities', 'Potencialidades', 'advanced_analytics', $this->hasPotentialities($pro)),
        ]));

        return [
            // §19: derived from the capability answer alone, never from a
            // plan name — a Base organization on an in-force Pro trial
            // resolves `advanced_analytics` to true and gets the Síntese.
            'title' => $allowsAdvancedAnalytics && $pro !== null ? 'Síntese de Acompanhamento do Aluno' : 'Ficha do Aluno',
            'sections' => $sections,
        ];
    }

    /**
     * @return array{key: string, label: string, capability: string}|null
     */
    protected function section(string $key, string $label, string $capability, bool $visible): ?array
    {
        return $visible ? ['key' => $key, 'label' => $label, 'capability' => $capability] : null;
    }

    /**
     * @param  array<string, mixed>  $pro
     */
    protected function hasPotentialities(array $pro): bool
    {
        $potentialities = (array) ($pro['potentialities'] ?? []);

        return $potentialities['narrative'] !== null
            || ($potentialities['strengths'] ?? []) !== []
            || ($potentialities['progressing'] ?? []) !== []
            || $potentialities['next_step'] !== null;
    }
}
