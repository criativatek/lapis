<?php

namespace App\Actions\Lessons;

use App\Models\AcademicYear;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * O ÂMBITO de uma reconciliação com o calendário: quem a pede, sobre que ano e,
 * opcionalmente, sobre que janela de datas.
 *
 *  - `forTeacher`: as turmas que o utilizador leciona (e só essas) no ano.
 *    Retira as aulas vazias (A) E desloca o planeamento das aulas com
 *    planeamento (B) — o planeamento é conteúdo pedagógico do professor.
 *  - `forOrganizationOwner`: todas as turmas do ano na organização. Retira SÓ
 *    as aulas vazias (A). Nunca toca em planeamento (B) nem em nada de C, e a
 *    pré-visualização mostra B/C apenas como contagens por turma.
 *
 * Só se constrói; as verificações de autorização vivem na ação, dentro da
 * transação, onde a resposta é verdadeira.
 */
final class CalendarReconciliationScope
{
    public const TEACHER = 'teacher';

    public const ORGANIZATION_OWNER = 'organization_owner';

    private function __construct(
        public readonly string $kind,
        public readonly AcademicYear $year,
        public readonly ?User $teacher,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}

    public static function forTeacher(User $teacher, AcademicYear $year, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        return new self(self::TEACHER, $year, $teacher, $from, $to);
    }

    public static function forOrganizationOwner(AcademicYear $year, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): self
    {
        return new self(self::ORGANIZATION_OWNER, $year, null, $from, $to);
    }

    /** Só o âmbito do professor desloca planeamento (B). */
    public function shiftsPlanning(): bool
    {
        return $this->kind === self::TEACHER;
    }
}
