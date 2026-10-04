<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A COR QUE CADA PROFESSOR DÁ A CADA TURMA, GUARDADA.
 *
 * Calculada no ecrã a partir das turmas visíveis, a cor de «8.º F» mudava
 * sempre que se filtrava, arquivava ou acrescentava uma turma. Gravada no
 * pivot (`class_teachers`) fica por professor — dois colegas de turma podem ver
 * cores diferentes — e nunca mais é recalculada: o modelo ClassTeacher só a
 * escolhe quando a ligação nasce.
 *
 * BACKFILL (uma vez, determinístico, SÓ com `DB::table` — uma migração não
 * pode depender de código que muda): para cada professor e cada ano letivo,
 * percorrem-se as suas ligações por `created_at` e depois por `id`; cada uma
 * recebe o tom MENOS usado entre os já atribuídos, neste percurso, a turmas
 * ATIVAS (`classes.archived_at IS NULL`); empate → primeiro da paleta. Uma
 * turma arquivada também recebe tom pela mesma regra, mas não conta como
 * «usado» para as seguintes. A paleta é a de App\Models\ClassIdentityTone,
 * copiada aqui de propósito.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PALETTE = ['blue', 'emerald', 'violet', 'amber', 'rose', 'stone'];

    public function up(): void
    {
        Schema::table('class_teachers', function (Blueprint $table): void {
            $table->string('identity_tone', 16)->nullable()->after('role');
        });

        $this->addCheck(
            'class_teachers',
            'class_teachers_identity_tone_check',
            "identity_tone IS NULL OR identity_tone IN ('".implode("','", self::PALETTE)."')",
        );

        $this->backfillIdentityTones();
    }

    public function down(): void
    {
        $this->dropCheck('class_teachers', 'class_teachers_identity_tone_check');

        Schema::table('class_teachers', function (Blueprint $table): void {
            $table->dropColumn('identity_tone');
        });
    }

    /**
     * Público para que o teste a corra sobre dados semeados — ver o teste da
     * migração.
     */
    public function backfillIdentityTones(): void
    {
        $rows = DB::table('class_teachers')
            ->join('classes', 'classes.id', '=', 'class_teachers.class_id')
            ->select([
                'class_teachers.id as pivot_id',
                'class_teachers.user_id',
                'classes.academic_year_id',
                'classes.archived_at',
            ])
            ->orderBy('class_teachers.user_id')
            ->orderBy('classes.academic_year_id')
            ->orderBy('class_teachers.created_at')
            ->orderBy('class_teachers.id')
            ->get();

        $walk = null;
        /** @var array<string, int> $usage */
        $usage = [];

        foreach ($rows as $row) {
            $key = $row->user_id.':'.$row->academic_year_id;

            if ($key !== $walk) {
                $walk = $key;
                $usage = array_fill_keys(self::PALETTE, 0);
            }

            $chosen = self::PALETTE[0];

            foreach (self::PALETTE as $tone) {
                if ($usage[$tone] < $usage[$chosen]) {
                    $chosen = $tone;
                }
            }

            DB::table('class_teachers')->where('id', $row->pivot_id)->update(['identity_tone' => $chosen]);

            if ($row->archived_at === null) {
                $usage[$chosen]++;
            }
        }
    }

    private function addCheck(string $table, string $name, string $expression): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    private function dropCheck(string $table, string $name): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
};
