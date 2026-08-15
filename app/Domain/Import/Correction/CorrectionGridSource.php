<?php

namespace App\Domain\Import\Correction;

/**
 * Where a correction grid came from.
 *
 * These are FILE FORMATS exported by other platforms, not integrations. LÁPIS
 * does not talk to Plickers or Intuitivo; it reads what a teacher exported from
 * them. Nothing here should ever be read as a promise of an API.
 *
 * Cases exist ahead of their parsers on purpose: the registry answers "is this
 * supported yet?" from the parsers actually registered, so an unimplemented
 * source is a clean "not yet" rather than a missing enum case somebody has to
 * add under pressure.
 */
enum CorrectionGridSource: string
{
    case Plickers = 'plickers';
    case Intuitivo = 'intuitivo';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::Plickers => 'Plickers',
            self::Intuitivo => 'Intuitivo',
            // The formats belong in the description, once. Carrying them in the
            // name as well meant «Excel/CSV» appeared three times on one screen.
            self::Generic => __('Folha de cálculo'),
        };
    }

    /**
     * Whether the source states, for each student, that they took part at all.
     *
     * Plickers does: it reports how many questions were answered, so «zero
     * answered» is a fact the file carries and «não participou nesta aplicação»
     * is a true sentence about it. Intuitivo reports marks and no attendance;
     * a spreadsheet a teacher typed reports whatever they typed. For those two
     * the phrase would be an invention, and an invention about attendance is
     * one a teacher could act on.
     *
     * A property of the FORMAT, stated once here, so no screen has to ask which
     * provider it is looking at (§6).
     */
    public function statesParticipation(): bool
    {
        return $this === self::Plickers;
    }

    /**
     * What to call the number the source itself arrived with.
     *
     * «Resultado na plataforma» is right for an export from a platform and wrong
     * for the teacher's own file, which came from no platform at all.
     */
    public function resultLabel(): string
    {
        return match ($this) {
            self::Plickers, self::Intuitivo => __('Resultado na plataforma'),
            self::Generic => __('Resultado no ficheiro'),
        };
    }

    /**
     * Whether the file explains its own structure, or the teacher has to.
     *
     * Asked by the interface to decide how much of the file it may describe
     * before anything has been mapped: a source that explains itself has real
     * counts the moment it is read, and one that does not has none yet — and
     * «0 alunos · 0 perguntas» on a file with thirty students in it is worse
     * than saying nothing (§6).
     */
    public function needsToBeDescribed(): bool
    {
        return $this === self::Generic;
    }

    /**
     * The granularity the wizard OPENS on for this source — nothing more.
     *
     * The modes themselves are provider-neutral: any source can be imported at
     * any of the three, and the teacher changes it in one click. What differs is
     * which one is right most of the time.
     *
     * Plickers states one score per student and usually assesses one thing at a
     * time, so it opens on the global result. Intuitivo states the test's
     * sections and what each is worth, and a Português paper routinely assesses
     * Leitura, Educação Literária, Gramática and Escrita in one sitting — so it
     * opens on the sections, which is both the most faithful reading of the file
     * and the least work for the teacher.
     */
    public function defaultResultMode(): string
    {
        return match ($this) {
            self::Intuitivo => ImportMapping::RESULT_PER_GROUP,
            self::Plickers, self::Generic => ImportMapping::RESULT_OVERALL,
        };
    }

    /**
     * What the teacher needs to have done before choosing this source.
     */
    public function hint(): string
    {
        return match ($this) {
            // Each of these names its own format, because this is the ONE place
            // the screen states it. Repeating it under the file button — where
            // it used to live as well — said «Excel/CSV» twice to somebody who
            // had already chosen.
            self::Plickers => __('O ficheiro CSV exportado do Plickers, com as respostas dos alunos.'),
            self::Intuitivo => __('A folha de notas exportada do Intuitivo (.xlsx).'),
            // Deliberately silent about the SHAPE. This source imports a global
            // result, results per domain OR question by question, and naming any
            // one of them here would send the teacher looking for the wrong file.
            self::Generic => __('Importe um ficheiro Excel (.xlsx) ou CSV (.csv).'),
        };
    }
}
