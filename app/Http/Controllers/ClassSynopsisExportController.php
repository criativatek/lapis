<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildClassSynopsis;
use App\Services\Assessment\Export\ClassSynopsisXlsxWriter;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * O Quadro Síntese em Excel.
 *
 * A MESMA LEITURA QUE O ECRÃ, e não uma segunda. `BuildClassSynopsis` é
 * chamado exatamente como a página o chama; o que muda é o que se faz com o
 * resultado. Um ficheiro construído a partir de outra consulta acabaria por
 * discordar do ecrã na primeira vez que uma das duas mudasse.
 *
 * EXPORTA TUDO, INDEPENDENTEMENTE DO QUE ESTÁ VISÍVEL. Os filtros e os grupos
 * fechados do ecrã são apresentação e vivem no browser; um ficheiro de dados
 * que omitisse colunas em silêncio conforme o estado de um `ref` seria uma
 * armadilha para quem o abre uma semana depois — a mesma decisão que a
 * exportação da Pauta já tomou.
 *
 * DEIXA RASTO DE AUDITORIA. Uma exportação com o ano inteiro de uma turma —
 * resultados, apreciações e decisões de todos os alunos — é precisamente o tipo
 * de leitura em massa que tem de ficar registada (§22.5). O rasto leva
 * contagens e identificadores; nunca nomes de alunos.
 *
 * O FICHEIRO NÃO É PÚBLICO E NÃO PASSA POR DISCO. Sai como resposta autenticada
 * desta rota, atrás da mesma `Gate::authorize('view', $class)` que abre o ecrã;
 * não há URL permanente, não há ficheiro guardado, não há nada para caducar.
 */
class ClassSynopsisExportController extends Controller
{
    public function __construct(
        protected BuildClassSynopsis $synopsis,
        protected ClassSynopsisXlsxWriter $xlsx,
        protected AuditLog $audit,
    ) {}

    public function xlsx(Request $request, SchoolClass $class): HttpResponse
    {
        Gate::authorize('view', $class);

        $synopsis = $this->synopsis->for($class);
        $scale = $class->profileVersion?->scale()->with('levels')->first();

        $context = [
            'class_label' => (string) $class->label,
            'subject' => (string) $class->subject->name,
            'academic_year' => (string) $class->academicYear->label,
            'scale_name' => $scale?->name,
            'scale_levels' => $scale === null ? [] : $scale->levels
                ->sortBy('sequence')
                ->map(fn ($level): array => [
                    'code' => (string) $level->code,
                    'label' => (string) $level->label,
                    'is_negative' => (bool) $level->is_negative,
                ])->values()->all(),
            'exported_on' => now()->format('d/m/Y H:i'),
        ];

        /** @var User $user */
        $user = $request->user();

        $this->audit->record(
            'class-synopsis.exported',
            $class,
            $user,
            'Quadro Síntese exportado para Excel.',
            [
                'format' => 'xlsx',
                'students' => count($synopsis['students']),
                'moments' => count($synopsis['moments']),
                'elements' => count($synopsis['elements']),
            ],
        );

        return response($this->xlsx->write($synopsis, $context), 200, [
            'Content-Type' => $this->xlsx->contentType(),
            // NOME SEGURO, e derivado. `Str::slug` deixa cair tudo o que não
            // seja letra, dígito ou hífen, portanto nenhum rótulo de turma pode
            // escrever um caminho, uma aspa ou uma quebra de linha no cabeçalho.
            'Content-Disposition' => 'attachment; filename="'.$this->fileStem($class).'.xlsx"',
        ]);
    }

    protected function fileStem(SchoolClass $class): string
    {
        $parts = array_filter([
            'quadro-sintese',
            Str::slug((string) $class->label),
            Str::slug((string) $class->subject->name),
            Str::slug((string) $class->academicYear->label),
        ]);

        return implode('-', $parts);
    }
}
