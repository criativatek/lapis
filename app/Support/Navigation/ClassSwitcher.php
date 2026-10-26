<?php

namespace App\Support\Navigation;

use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as MatchedRoute;

/**
 * As turmas para que o chip da barra superior deixa trocar, e para onde.
 *
 * SÓ EXISTE QUANDO HÁ UMA TURMA EM VISTA. A lista são as turmas do mesmo ano
 * letivo que o professor leciona — a mesma regra que `SchoolClassPolicy::view`
 * (`teaches`) aplica à turma ligada à rota, escrita como consulta para ser uma
 * só — sem as arquivadas (a atual entra sempre, mesmo arquivada, para o chip
 * dizer onde se está). Nunca uma turma de outro professor, nunca de outra
 * organização: o scope global trata da organização, `taughtBy` do professor.
 *
 * A TROCA MANTÉM A ROTA. Quem está nas classificações do 7.º A e escolhe o
 * 7.º B quer as classificações do 7.º B, não a página da turma. Só os
 * parâmetros que fazem sentido noutra turma viajam: a própria turma e o
 * período (que é do ano letivo, não da turma). Qualquer outro parâmetro — um
 * aluno, uma pauta guardada, uma avaliação intercalar, um token de importação —
 * pertence à turma de origem, e o destino cai para a página da turma.
 */
class ClassSwitcher
{
    /** Parâmetros de rota que valem noutra turma do mesmo ano letivo. */
    private const PORTABLE_PARAMETERS = ['class', 'period'];

    /**
     * @return list<array{ulid: string, label: string, subject: string, gradeLevel: ?string, href: string, is_current: bool}>
     */
    public function optionsFor(Request $request, User $teacher, SchoolClass $current): array
    {
        // UMA consulta, não duas: o nome da disciplina vem por subconsulta em
        // vez de um eager load, porque isto corre em TODAS as páginas de turma
        // e cada uma tem o seu teto de consultas (§79).
        $table = $current->getTable();

        $classes = SchoolClass::query()
            ->taughtBy($teacher)
            ->where('academic_year_id', $current->academic_year_id)
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhere($current->getKeyName(), $current->getKey()))
            ->addSelect([
                $table.'.*',
                'subject_name' => Subject::query()->select('name')->whereColumn('subjects.id', $table.'.subject_id')->limit(1),
            ])
            ->orderBy('label')
            ->orderBy('id')
            ->get();

        return array_values($classes->map(fn (SchoolClass $class): array => [
            'ulid' => $class->ulid,
            'label' => $class->label,
            'subject' => (string) $class->getAttribute('subject_name'),
            'gradeLevel' => $class->grade_level,
            'href' => $this->destinationFor($request, $class),
            'is_current' => $class->is($current),
        ])->all());
    }

    /**
     * A página equivalente na outra turma, ou a página da turma quando a
     * rota atual leva algo que só existe na turma de origem.
     */
    public function destinationFor(Request $request, SchoolClass $class): string
    {
        $route = $request->route();

        if (! $route instanceof MatchedRoute || $route->getName() === null || ! in_array('GET', $route->methods(), true)) {
            return route('classes.show', $class);
        }

        $parameters = $route->parameters();

        foreach (array_keys($parameters) as $name) {
            if (! in_array($name, self::PORTABLE_PARAMETERS, true)) {
                return route('classes.show', $class);
            }
        }

        return route($route->getName(), [...$parameters, 'class' => $class]);
    }
}
