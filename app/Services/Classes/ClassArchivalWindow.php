<?php

namespace App\Services\Classes;

use App\Models\Lesson;
use App\Models\LessonOrigin;
use App\Models\LessonStatus;
use App\Models\SchoolClass;
use Carbon\CarbonImmutable;

/**
 * A REGRA TEMPORAL DO ARQUIVAMENTO, NUM SÍTIO SÓ.
 *
 * Arquivar uma turma não fecha os seus tempos do horário — nem deve: o
 * histórico fica. O que o arquivamento diz é mais estreito e é TEMPORAL: a
 * partir do dia em que foi arquivada, aquela turma já não tem horário. O
 * Horário do Professor (TeacherTimetableController::occursInWeek) sempre
 * decidiu assim; a materialização e a vista semanal não, e por isso a turma 23
 * — arquivada a 13/09 — continuava a fazer nascer e a mostrar aulas de 25/09 e
 * de outubro. Escrita aqui, a condição passa a ser a MESMA nos três sítios.
 *
 * A FRONTEIRA É O PRÓPRIO DIA DO ARQUIVAMENTO, e é inclusiva contra a turma:
 * `ArchiveSchoolClass` grava `now()`, por isso a turma arquivada num dia já não
 * tem horário nesse mesmo dia — tem até à véspera. É a leitura que o Horário já
 * fazia e que se repete aqui, tal e qual.
 *
 * DATAS LOCAIS DA ORGANIZAÇÃO, e nunca instantes: `archived_at` é um instante
 * UTC, mas a pergunta («aquele dia ainda era dela?») é sobre um DIA no
 * calendário de quem usa a aplicação. Comparar meias-noites de fusos que não
 * têm de coincidir tornaria a resposta dependente da hora legal em vigor.
 *
 * E NUNCA `archived_at IS NULL` global: numa data anterior ao arquivamento a
 * turma estava viva, e o que lá aconteceu é dela para sempre.
 */
final class ClassArchivalWindow
{
    /**
     * O mesmo fuso que MaterializeLessonsForRange e WeeklyLessonsQuery já
     * assumem quando a organizacao nao diz outro.
     */
    private const FALLBACK_TIMEZONE = 'Europe/Lisbon';

    /**
     * O fuso em que as datas desta turma são lidas — o da organização a que
     * pertence, com o fuso de omissão da aplicação como plano B para a turma
     * cuja organização não esteja carregável (fixtures, exportações).
     */
    public function timezoneFor(SchoolClass $class): string
    {
        $timezone = $class->relationLoaded('organization')
            ? $class->organization?->timezone
            : $class->organization()->first()?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : self::FALLBACK_TIMEZONE;
    }

    /**
     * O dia (local) em que a turma foi arquivada, ou `null` se não está
     * arquivada. É o primeiro dia que JÁ NÃO é dela.
     */
    public function archivedOn(SchoolClass $class, ?string $timezone = null): ?string
    {
        if ($class->archived_at === null) {
            return null;
        }

        return CarbonImmutable::instance($class->archived_at)
            ->setTimezone($timezone ?? $this->timezoneFor($class))
            ->toDateString();
    }

    /**
     * A turma ainda tinha horário neste dia? `$date` é uma data local «Y-m-d».
     */
    public function coversDate(SchoolClass $class, string $date, ?string $timezone = null): bool
    {
        $archivedOn = $this->archivedOn($class, $timezone);

        return $archivedOn === null || $archivedOn > $date;
    }

    /**
     * O último dia para o qual ainda podem NASCER aulas automáticas — a
     * véspera do arquivamento —, ou `null` quando a turma não está arquivada e
     * portanto não há limite nenhum a impor.
     */
    public function lastScheduledDay(SchoolClass $class, ?string $timezone = null): ?CarbonImmutable
    {
        $archivedOn = $this->archivedOn($class, $timezone);

        if ($archivedOn === null) {
            return null;
        }

        $tz = $timezone ?? $this->timezoneFor($class);

        return CarbonImmutable::parse($archivedOn, $tz)->startOfDay()->subDay();
    }

    /**
     * Esta aula é uma OCORRÊNCIA VAZIA DO HORÁRIO que o arquivamento deixou
     * para trás — e portanto a única espécie de aula que a vista semanal pode
     * deixar de mostrar sem esconder trabalho nenhum?
     *
     * Tem de ser tudo ao mesmo tempo: nascida do horário (nunca uma aula
     * introduzida à mão), ainda por preparar, aberta, sem sumário, sem plano,
     * sem uma única linha de assiduidade — nem sequer de rascunho — e sem a
     * consolidação. «Por preparar» SOZINHO não chega: uma aula por preparar
     * pode já ter um plano escrito ou um rascunho de faltas, e essa é trabalho
     * pedagógico que continua a aparecer.
     *
     * Exige `summary` carregado e as contagens `plan_count` e
     * `attendances_count` — quem filtra por isto carrega-as com a lista
     * inteira, para que uma semana não faça três consultas por linha.
     */
    public function isEmptyScheduledOccurrence(Lesson $lesson): bool
    {
        return $lesson->origin === LessonOrigin::Schedule
            && $lesson->status === LessonStatus::Preparation
            && $lesson->outcome === null
            && $lesson->attendance_recorded_at === null
            && trim((string) $lesson->summary?->content) === ''
            && (int) ($lesson->plan_count ?? 0) === 0
            && (int) ($lesson->attendances_count ?? 0) === 0;
    }
}
