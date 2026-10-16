<?php

namespace App\Services\Import\Backup;

use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * UM RESTAURO PARA UM DESTINO COM AULAS EXISTENTES NÃO PODE DUPLICAR LIÇÕES
 * (0.169.0).
 *
 * O restauro conserva as aulas do destino. Uma aula do ficheiro que coincide
 * com uma aula do destino (o mesmo ulid, ou a chave de negócio) fica
 * `existing`/`conflict` e o destino ganha; as restantes entram como `new` AO
 * LADO das existentes, com `lesson_number`, `lesson_number_end` e
 * `lesson_number_manual` copiados tal como estão. Sem esta verificação, um
 * ficheiro com a Lição 12 manual numa aula de 22/10 restaurava-a numa turma
 * onde a Lição 12 já era de outra aula — duas aulas com a mesma lição fixa, que
 * nenhuma derivação sabe desfazer.
 *
 * O QUE CONTA COMO LIÇÃO FIXA, nas duas pontas: uma aula com número indicado
 * pelo professor (`lesson_number_manual` e número), ou fechada com número
 * (lecionada ou atividade da turma). As aulas livres — abertas e não manuais —
 * não contam: o número delas é uma sugestão que a derivação refaz.
 *
 * UNIDADES DE LIÇÃO. As aulas de grupo com a mesma `lesson_unit_key` são a mesma
 * lição e partilham o intervalo; as restantes são uma unidade cada. Uma unidade
 * do ficheiro com a MESMA chave de uma unidade do destino é a mesma lição: o
 * intervalo igual é aceite, o diferente é conflito. Unidades diferentes cujos
 * intervalos fixos se sobrepõem são conflito.
 *
 * O resultado alimenta uma lista BLOQUEANTE, não linhas `conflict`: uma linha
 * `conflict` é saltada pela escrita, e deixaria a aula (e o sumário, o plano e
 * as faltas dela) de fora — um restauro parcial, que não é o que se quer. As
 * mensagens têm só datas, números e o rótulo da turma; nunca nomes de alunos.
 *
 * A verificação DENTRO do ficheiro (sobreposições manuais ⇒ linha `invalid`) é
 * outra, feita à validação, e continua a existir.
 */
class FindLessonNumberCollisions
{
    private const TIMEZONE = 'Europe/Lisbon';

    /** Mais do que isto torna a pré-visualização ilegível; o resto resume-se. */
    private const MAX_MESSAGES = 20;

    /**
     * @param  array<int, array<string, mixed>>  $lessonRows  as linhas já classificadas de `lessons`
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return list<string>
     */
    public function find(array $lessonRows, Collection $classesByUlid, Organization $destination): array
    {
        $incoming = $this->incomingUnitsByClass($lessonRows, $classesByUlid);

        if ($incoming === []) {
            return [];
        }

        $destinationLessons = Lesson::query()
            ->where('organization_id', $destination->getKey())
            ->whereIn('class_id', array_keys($incoming))
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', '!=', LessonOutcome::TeacherAbsent->value))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->groupBy('class_id');

        $messages = [];

        foreach ($incoming as $classId => $classIncoming) {
            $existing = $this->destinationUnits($destinationLessons->get($classId, collect()));

            if ($existing === []) {
                continue;
            }

            foreach ($classIncoming['units'] as $unit) {
                foreach ($existing as $other) {
                    $message = $this->collision($unit, $other, $classIncoming['label']);

                    if ($message !== null) {
                        $messages[] = $message;
                    }
                }
            }
        }

        $messages = array_values(array_unique($messages));

        if (count($messages) > self::MAX_MESSAGES) {
            $rest = count($messages) - self::MAX_MESSAGES;
            $messages = array_slice($messages, 0, self::MAX_MESSAGES);
            $messages[] = (string) __('E mais :count conflito(s) de numeração.', ['count' => (string) $rest]);
        }

        return $messages;
    }

    /**
     * As unidades FIXAS que as aulas `new` do ficheiro trazem, por turma de
     * destino que já existe.
     *
     * @param  array<int, array<string, mixed>>  $lessonRows
     * @param  Collection<string, array<string, mixed>>  $classesByUlid
     * @return array<int, array{label: string, units: list<array{key: string|null, start: int, end: int, date: string}>}>
     */
    private function incomingUnitsByClass(array $lessonRows, Collection $classesByUlid): array
    {
        $byClass = [];

        foreach ($lessonRows as $row) {
            if (($row['classification'] ?? null) !== 'new' || ! $this->isFixedRow($row)) {
                continue;
            }

            $class = $classesByUlid->get($row['class_ulid']);

            if ($class === null || ($class['classification'] ?? null) !== 'existing' || ! isset($class['existing_id'])) {
                continue;
            }

            $classId = (int) $class['existing_id'];
            $key = is_string($row['lesson_unit_key'] ?? null) && $row['lesson_unit_key'] !== '' ? $row['lesson_unit_key'] : null;
            $start = (int) $row['lesson_number'];
            $end = max($start, (int) ($row['lesson_number_end'] ?? $start));
            $byClass[$classId] ??= ['label' => (string) ($class['label'] ?? ''), 'units' => []];

            // Uma unidade de grupo conta uma vez: o primeiro membro fixo manda.
            if ($key !== null) {
                foreach ($byClass[$classId]['units'] as $unit) {
                    if ($unit['key'] === $key) {
                        continue 2;
                    }
                }
            }

            $byClass[$classId]['units'][] = ['key' => $key, 'start' => $start, 'end' => $end, 'date' => $this->date((string) $row['starts_at'])];
        }

        return $byClass;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isFixedRow(array $row): bool
    {
        if (! is_int($row['lesson_number'] ?? null)) {
            return false;
        }

        $outcome = $row['outcome'] ?? null;

        if ($outcome === LessonOutcome::TeacherAbsent->value) {
            return false;
        }

        return ($row['lesson_number_manual'] ?? false) === true
            || ($row['status'] ?? null) === LessonStatus::Taught->value
            || in_array($outcome, [LessonOutcome::Taught->value, LessonOutcome::ClassExternalActivity->value], true);
    }

    private function isFixedLesson(Lesson $lesson): bool
    {
        if ($lesson->lesson_number === null || $lesson->outcome === LessonOutcome::TeacherAbsent) {
            return false;
        }

        return $lesson->lesson_number_manual
            || $lesson->status === LessonStatus::Taught
            || in_array($lesson->outcome, [LessonOutcome::Taught, LessonOutcome::ClassExternalActivity], true);
    }

    /**
     * As unidades FIXAS que o destino já tem na turma.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array{key: string|null, start: int, end: int, date: string}>
     */
    private function destinationUnits(Collection $lessons): array
    {
        $units = [];
        $seenKeys = [];

        foreach ($lessons as $lesson) {
            if (! $this->isFixedLesson($lesson)) {
                continue;
            }

            $key = $lesson->lesson_unit_key !== null && $lesson->lesson_unit_key !== '' ? $lesson->lesson_unit_key : null;

            if ($key !== null) {
                if (isset($seenKeys[$key])) {
                    continue;
                }

                $seenKeys[$key] = true;
            }

            $start = (int) $lesson->lesson_number;
            $units[] = [
                'key' => $key,
                'start' => $start,
                'end' => max($start, (int) ($lesson->lesson_number_end ?? $start)),
                'date' => $lesson->starts_at->setTimezone(self::TIMEZONE)->format('d/m/Y'),
            ];
        }

        return $units;
    }

    /**
     * @param  array{key: string|null, start: int, end: int, date: string}  $incoming
     * @param  array{key: string|null, start: int, end: int, date: string}  $existing
     */
    private function collision(array $incoming, array $existing, string $classLabel): ?string
    {
        $sameLesson = $incoming['key'] !== null && $incoming['key'] === $existing['key'];

        if ($sameLesson) {
            if ($incoming['start'] === $existing['start'] && $incoming['end'] === $existing['end']) {
                return null;
            }

            return (string) __('A aula de :date (:class) do ficheiro tem :incoming, mas no destino a mesma lição (aula de :other) tem :existing. Corrija o número no destino ou restaure noutra conta.', [
                'date' => $incoming['date'], 'class' => $classLabel, 'other' => $existing['date'],
                'incoming' => $this->describe($incoming), 'existing' => $this->describe($existing),
            ]);
        }

        if ($incoming['start'] > $existing['end'] || $existing['start'] > $incoming['end']) {
            return null;
        }

        if ($incoming['start'] === $incoming['end'] && $existing['start'] === $existing['end']) {
            return (string) __('A aula de :date (:class) do ficheiro tem a Lição :number, que no destino já é da aula de :other. Corrija o número no destino ou restaure noutra conta.', [
                'date' => $incoming['date'], 'class' => $classLabel, 'number' => (string) $incoming['start'], 'other' => $existing['date'],
            ]);
        }

        return (string) __('A aula de :date (:class) do ficheiro tem :incoming, que no destino se sobrepõe :existing da aula de :other. Corrija o número no destino ou restaure noutra conta.', [
            'date' => $incoming['date'], 'class' => $classLabel, 'other' => $existing['date'],
            'incoming' => $this->describe($incoming), 'existing' => $this->describe($existing, dative: true),
        ]);
    }

    /**
     * @param  array{key: string|null, start: int, end: int, date: string}  $unit
     */
    private function describe(array $unit, bool $dative = false): string
    {
        return $unit['start'] === $unit['end']
            ? ($dative ? "à Lição {$unit['start']}" : "a Lição {$unit['start']}")
            : ($dative ? 'às lições' : 'as lições')." {$unit['start']}–{$unit['end']}";
    }

    private function date(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone(self::TIMEZONE)->format('d/m/Y');
    }
}
