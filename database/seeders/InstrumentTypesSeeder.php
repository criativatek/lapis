<?php

namespace Database\Seeders;

use App\Models\InstrumentType;
use Illuminate\Database\Seeder;

/**
 * The instrument types every organization starts with (§12.1). Shared system
 * rows (organization_id NULL); a teacher can add their own.
 *
 * default_purpose is only a starting label — it never decides whether the
 * instrument counts toward the classification (§4.1). "A classificação como
 * formativa ou sumativa não altera automaticamente o peso."
 *
 * Idempotent — safe to re-run.
 */
class InstrumentTypesSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, default_purpose: string}>
     */
    protected const TYPES = [
        ['code' => 'TEST', 'name' => 'Teste global', 'default_purpose' => 'summative'],
        ['code' => 'WORKSHEET', 'name' => 'Ficha', 'default_purpose' => 'formative'],
        ['code' => 'QUESTION_CLASS', 'name' => 'Questão-aula', 'default_purpose' => 'formative'],
        ['code' => 'WRITTEN_WORK', 'name' => 'Trabalho escrito', 'default_purpose' => 'summative'],
        ['code' => 'ORAL', 'name' => 'Apresentação oral', 'default_purpose' => 'summative'],
        ['code' => 'PROJECT', 'name' => 'Projeto', 'default_purpose' => 'summative'],
        ['code' => 'PRACTICAL', 'name' => 'Atividade prática', 'default_purpose' => 'formative'],
        ['code' => 'OBSERVATION', 'name' => 'Observação', 'default_purpose' => 'formative'],
        ['code' => 'DIAGNOSTIC', 'name' => 'Diagnóstico', 'default_purpose' => 'diagnostic'],
    ];

    public function run(): void
    {
        foreach (self::TYPES as $type) {
            InstrumentType::withoutGlobalScope('typeVisibility')->updateOrCreate(
                ['organization_id' => null, 'code' => $type['code']],
                ['name' => $type['name'], 'default_purpose' => $type['default_purpose'], 'is_active' => true],
            );
        }
    }
}
