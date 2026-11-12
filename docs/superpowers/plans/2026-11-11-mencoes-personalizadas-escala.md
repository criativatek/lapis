# Menções personalizadas da escala de níveis — plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Perfis novos abrem em «Escala 1 a 5»; o professor pode dar às cinco menções os nomes da sua escola, por perfil; a Pauta avisa quando a escala do perfil não tem menções.

**Architecture:** Uma escala da organização **derivada** da «Escala 1 a 5» do sistema (`scales.derived_from_scale_id`), com os cinco `scale_levels` copiados campo a campo e só `label` diferente. Um serviço (`DeriveScaleWithLabels`) é o único sítio que cria ou atualiza essas escalas e aplica a regra copy-on-write já decidida no modelo de domínio (§2.3): congelada → nova cópia. Nenhuma tabela nem coluna nova; tudo o que lê `ScaleLevel.label` continua igual.

**Tech Stack:** Laravel 12 / PHP 8.4 (Herd: `export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"`), PHPUnit 12, Pint, PHPStan; Inertia + Vue 3 + TypeScript, vitest/@vue/test-utils, eslint, vue-tsc (precisa de `npm run build` antes, por causa do Wayfinder). Worktree `D:/worktrees/lapis-draft-recovery`, branch `feat/scale-custom-mentions`, partida da 0.192.0.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-11-11-mencoes-personalizadas-escala-design.md`.
- Por defeito nada muda: «Fraco, Insuficiente, Suficiente, Bom, Muito Bom» continua a ser o que todos veem.
- Só `label` é editável. Nunca `code`, `inovar_code`, `sequence`, `numeric_value`, `normalized_value`, `band_min_normalized`, `band_max_normalized`, `is_negative`, nem o número de níveis.
- Escalas do sistema (`organization_id IS NULL`) são imutáveis.
- Validação dos nomes: cinco, obrigatórios, 1 a 64 caracteres, sem repetidos depois de `trim` e ignorando maiúsculas.
- Nome da escala derivada: «Escala 1 a 5 · {nome do perfil}»; em colisão na organização, sufixo « (2)», « (3)»…
- Versão alvo 0.193.0; só código, zero migrations.
- Texto novo diz sempre «Lapispro», nunca «LÁPIS».
- Os testes de BD correm em SQLite por omissão; correr os testes novos também em MySQL antes da PR (`lapispro-testes-sqlite-escondem-checks`).
- Comandos de teste: `php artisan test --filter=Nome` (PHP), `npx vitest run caminho` (Vue), `vendor/bin/pint --dirty`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npx eslint resources/js`, `npx vue-tsc --noEmit`.

---

## Estrutura de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `app/Services/Assessment/DeriveScaleWithLabels.php` (novo) | Criar/atualizar a escala derivada; copy-on-write; nome único |
| `app/Http/Requests/ScaleMentionsRequest.php` (novo) | Validar `labels` (5) e `profile_name` |
| `app/Http/Controllers/ScaleMentionsController.php` (novo) | `POST scales/{scale}/mencoes`; autorização; auditoria; devolve `scale_id` |
| `routes/web.php` | A rota, ao lado de `scales.store` |
| `app/Http/Controllers/AssessmentProfileController.php` | `formOptions()` passa `levels` de cada escala e `default_scale_id` |
| `resources/js/pages/assessment-profiles/ProfileForm.vue` | Pré-seleção; botão e diálogo «Personalizar menções» |
| `resources/js/pages/assessment-profiles/Create.vue`, `Edit.vue` | Passam `defaultScaleId` / tipo `Option.levels` |
| `app/Http/Controllers/EvaluationSheetController.php` | `profile_edit_url` quando a escala não tem bandas e o utilizador pode editar |
| `resources/js/pages/evaluation-sheets/Show.vue` | A nota «sem menções» com ligação |
| Testes | `tests/Feature/Assessment/DeriveScaleWithLabelsTest.php`, `ScaleMentionsTest.php`, `AssessmentProfileTest.php` (+2), `EvaluationSheetControllerTest.php` (+3), `ProfileForm.test.ts` (+4), `evaluation-sheets/Show.test.ts` (+3) |
| `config/app.php`, `CHANGELOG.md`, `docs/domain-model.md` §2.3 | Versão 0.193.0 e documentação |

---

### Task 1: `DeriveScaleWithLabels` — criar a escala derivada

**Files:**
- Create: `app/Services/Assessment/DeriveScaleWithLabels.php`
- Test: `tests/Feature/Assessment/DeriveScaleWithLabelsTest.php`

**Interfaces:**
- Consumes: `App\Models\Scale` (`levels()` ordenado por `sequence`, `isSystem()`, `isFrozen()`, `#[Fillable(['name','kind','min_value','max_value'])]`), `App\Models\ScaleLevel` (fillable: code, inovar_code, label, sequence, numeric_value, normalized_value, band_min_normalized, band_max_normalized, is_negative), `App\Support\Tenancy\CurrentOrganization` (`id()`), coluna `scales.derived_from_scale_id` (já existe na migration `2026_07_18_000300`).
- Produces: `DeriveScaleWithLabels::for(Scale $source, array $labels, string $profileName): Scale` — devolve a escala derivada (nova ou atualizada) com `levels` carregados. `$labels` é uma lista de 5 strings por ordem de `sequence`. Lança `Illuminate\Validation\ValidationException` (chave `labels`) quando a origem não é de níveis ou não tem cinco níveis com bandas.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\Scale;
use App\Models\User;
use App\Services\Assessment\DeriveScaleWithLabels;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A escala derivada É um nível de escala a sério: copia tudo da origem e só
 * muda os nomes. É isso que faz a pauta, as fotografias e o Inovar funcionarem
 * sem uma linha alterada.
 */
class DeriveScaleWithLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), $callback);
    }

    protected function system(): Scale
    {
        return Scale::where('name', 'Escala 1 a 5')->with('levels')->firstOrFail();
    }

    /** @return list<string> */
    protected function schoolLabels(): array
    {
        return ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'];
    }

    #[Test]
    public function copies_the_five_levels_field_by_field_and_changes_only_the_label(): void
    {
        $derived = $this->inTenant(fn () => app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português'));

        $this->assertSame('Escala 1 a 5 · Português', $derived->name);
        $this->assertSame('level', $derived->kind);
        $this->assertSame($this->user->personalOrganization()->id, $derived->organization_id);
        $this->assertSame($this->system()->id, $derived->derived_from_scale_id);
        $this->assertNull($derived->frozen_at);
        $this->assertSame('1.000', (string) $derived->min_value);
        $this->assertSame('5.000', (string) $derived->max_value);

        $source = $this->system()->levels->values();
        $levels = $derived->levels->values();
        $this->assertCount(5, $levels);

        foreach ($levels as $index => $level) {
            $this->assertSame($source[$index]->code, $level->code);
            $this->assertSame($source[$index]->inovar_code, $level->inovar_code);
            $this->assertSame($source[$index]->sequence, $level->sequence);
            $this->assertSame((string) $source[$index]->numeric_value, (string) $level->numeric_value);
            $this->assertSame((string) $source[$index]->band_min_normalized, (string) $level->band_min_normalized);
            $this->assertSame((string) $source[$index]->band_max_normalized, (string) $level->band_max_normalized);
            $this->assertSame($source[$index]->is_negative, $level->is_negative);
            $this->assertSame($this->schoolLabels()[$index], $level->label);
        }

        // A origem não foi tocada.
        $this->assertSame('Fraco', $this->system()->levels->first()->label);
    }

    #[Test]
    public function a_second_profile_with_the_same_name_gets_a_numbered_suffix(): void
    {
        $this->inTenant(function (): void {
            app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $second = app(DeriveScaleWithLabels::class)->for($this->system(), ['F', 'I', 'S', 'B', 'MB'], 'Português');

            $this->assertSame('Escala 1 a 5 · Português (2)', $second->name);
        });
    }

    #[Test]
    public function an_unfrozen_derived_scale_is_updated_in_place(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $again = app(DeriveScaleWithLabels::class)->for($derived, ['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Excelente'], 'Português');

            $this->assertSame($derived->id, $again->id);
            $this->assertSame('Excelente', $again->levels->last()->label);
            $this->assertSame(1, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
        });
    }

    #[Test]
    public function a_frozen_derived_scale_is_left_alone_and_a_new_one_is_derived_from_the_same_system_scale(): void
    {
        $this->inTenant(function (): void {
            $derived = app(DeriveScaleWithLabels::class)->for($this->system(), $this->schoolLabels(), 'Português');
            $derived->forceFill(['frozen_at' => Carbon::parse('2026-11-01 10:00:00')])->save();

            $fresh = app(DeriveScaleWithLabels::class)->for($derived->refresh(), ['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Excelente'], 'Português');

            $this->assertNotSame($derived->id, $fresh->id);
            $this->assertSame($this->system()->id, $fresh->derived_from_scale_id);
            $this->assertSame('Escala 1 a 5 · Português (2)', $fresh->name);
            $this->assertNull($fresh->frozen_at);
            $this->assertSame('Excelente', $fresh->levels->last()->label);
            // A congelada continua exatamente como estava.
            $this->assertSame('Muito bom', $derived->refresh()->levels->last()->label);
        });
    }

    #[Test]
    public function refuses_an_interval_scale_and_a_level_scale_without_bands(): void
    {
        $this->inTenant(function (): void {
            $percentage = Scale::where('name', 'Percentagem (0 a 100)')->firstOrFail();

            try {
                app(DeriveScaleWithLabels::class)->for($percentage, $this->schoolLabels(), 'Português');
                $this->fail('Uma escala de intervalo não tem menções para personalizar.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('labels', $exception->errors());
            }

            $bare = Scale::create(['name' => 'Níveis sem bandas', 'kind' => 'level', 'min_value' => 1, 'max_value' => 3]);
            $bare->levels()->create(['code' => '1', 'label' => 'Um', 'sequence' => 1, 'is_negative' => true]);

            $this->expectException(ValidationException::class);
            app(DeriveScaleWithLabels::class)->for($bare, $this->schoolLabels(), 'Português');
        });
    }
}
```

- [ ] **Step 2: Correr o teste e ver falhar**

Run: `php artisan test --filter=DeriveScaleWithLabelsTest`
Expected: FAIL — `Class "App\Services\Assessment\DeriveScaleWithLabels" not found`.

- [ ] **Step 3: Implementar o serviço**

```php
<?php

namespace App\Services\Assessment;

use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * As menções da escola, por perfil — sem tocar na escala do sistema.
 *
 * UMA ESCALA DERIVADA É UM NÍVEL DE ESCALA A SÉRIO. Copia os cinco níveis da
 * «Escala 1 a 5» campo a campo (código, Inovar, bandas, valor numérico, sinal)
 * e só muda os nomes. Por isso a pauta, as fotografias, os relatórios, a
 * autoavaliação e a exportação Inovar continuam a ler `ScaleLevel.label` por
 * identidade do nível, sem uma linha alterada — a alternativa (nomes
 * alternativos guardados no perfil) obrigaria cada um desses sítios a saber
 * aplicar a substituição, e qualquer um esquecido mostraria «Fraco» à mesma.
 *
 * COPY-ON-WRITE, como o modelo de domínio decidiu (§2.3): enquanto nenhuma
 * versão ativa de perfil usar a escala derivada, os nomes editam-se em cima;
 * depois de congelada (`frozen_at`, posto por ActivateProfileVersion), editar
 * cria uma escala nova a partir da MESMA origem do sistema. As versões
 * antigas, as classificações decididas e as fotografias continuam a apontar à
 * escala antiga, intacta.
 *
 * O ÚNICO SÍTIO que cria estas escalas. O nome, o rasto (`derived_from_scale_id`)
 * e a lista de campos copiados vivem aqui e em mais lado nenhum.
 */
class DeriveScaleWithLabels
{
    /** Os campos de um nível que viajam intactos da origem para a cópia. */
    protected const COPIED_LEVEL_FIELDS = [
        'code', 'inovar_code', 'sequence', 'numeric_value', 'normalized_value',
        'band_min_normalized', 'band_max_normalized', 'is_negative',
    ];

    public function __construct(protected CurrentOrganization $tenant) {}

    /**
     * @param  list<string>  $labels  cinco nomes, por ordem de `sequence`
     */
    public function for(Scale $source, array $labels, string $profileName): Scale
    {
        $origin = $this->originOf($source);
        $this->guardOrigin($origin, $labels);

        return DB::transaction(function () use ($source, $origin, $labels, $profileName): Scale {
            // Derivada, da organização, e ainda livre: os nomes mudam em cima.
            if (! $source->isSystem() && $source->derived_from_scale_id !== null && ! $source->isFrozen()) {
                $this->applyLabels($source, $labels);

                return $source->refresh()->load('levels');
            }

            $derived = Scale::create([
                'name' => $this->uniqueName($profileName),
                'kind' => $origin->kind,
                'min_value' => $origin->min_value,
                'max_value' => $origin->max_value,
            ]);
            $derived->forceFill(['derived_from_scale_id' => $origin->id])->save();

            foreach ($origin->levels->sortBy('sequence')->values() as $index => $level) {
                $derived->levels()->create([
                    ...collect($level->getAttributes())->only(self::COPIED_LEVEL_FIELDS)->all(),
                    'label' => $labels[$index],
                ]);
            }

            return $derived->refresh()->load('levels');
        });
    }

    /** A escala do sistema de onde tudo deriva — a própria, ou a origem de uma derivada. */
    protected function originOf(Scale $scale): Scale
    {
        if ($scale->derived_from_scale_id === null) {
            return $scale->load('levels');
        }

        return Scale::withoutGlobalScope('scaleVisibility')
            ->with('levels')
            ->findOrFail($scale->derived_from_scale_id);
    }

    /**
     * @param  list<string>  $labels
     */
    protected function guardOrigin(Scale $origin, array $labels): void
    {
        $withBands = $origin->levels
            ->filter(fn (ScaleLevel $level): bool => $level->band_min_normalized !== null && $level->band_max_normalized !== null)
            ->count();

        if ($origin->kind !== 'level' || $withBands !== $origin->levels->count() || $origin->levels->isEmpty()) {
            throw ValidationException::withMessages([
                'labels' => 'Só uma escala de níveis com bandas de conversão tem menções para personalizar.',
            ]);
        }

        if (count($labels) !== $origin->levels->count()) {
            throw ValidationException::withMessages([
                'labels' => "A escala tem {$origin->levels->count()} níveis; recebeu ".count($labels).' nomes.',
            ]);
        }
    }

    /**
     * @param  list<string>  $labels
     */
    protected function applyLabels(Scale $scale, array $labels): void
    {
        foreach ($scale->levels()->orderBy('sequence')->get()->values() as $index => $level) {
            $level->update(['label' => $labels[$index]]);
        }
    }

    protected function uniqueName(string $profileName): string
    {
        $base = 'Escala 1 a 5 · '.trim($profileName);
        $name = $base;
        $suffix = 2;

        while (Scale::withoutGlobalScope('scaleVisibility')
            ->where('organization_id', $this->tenant->id())
            ->where('name', $name)
            ->exists()) {
            $name = "{$base} ({$suffix})";
            $suffix++;
        }

        return $name;
    }
}
```

Nota: `Scale::create` recebe só os campos do `#[Fillable]`; `derived_from_scale_id` vai por `forceFill`. O `organization_id` é carimbado pelo `creating` do modelo.

- [ ] **Step 4: Correr o teste e ver passar**

Run: `php artisan test --filter=DeriveScaleWithLabelsTest`
Expected: PASS (5 testes). Se `uniqueName` encontrar um nome com mais de 120 caracteres, a BD recusa: nomes de perfil têm no máximo 120 e o prefixo tem 15 — aceitar o corte em `substr($base, 0, 110)` **só se** o teste de Pint/PHPStan apontar o caso; caso contrário não antecipar.

- [ ] **Step 5: Pint, PHPStan e commit**

```bash
vendor/bin/pint --dirty && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Services/Assessment/DeriveScaleWithLabels.php tests/Feature/Assessment/DeriveScaleWithLabelsTest.php
git commit -m "feat(scales): DeriveScaleWithLabels — escala derivada da «Escala 1 a 5» com as menções da escola"
```

---

### Task 2: Rota, pedido e controlador `POST scales/{scale}/mencoes`

**Files:**
- Create: `app/Http/Requests/ScaleMentionsRequest.php`
- Create: `app/Http/Controllers/ScaleMentionsController.php`
- Modify: `routes/web.php` (logo depois da linha `->middleware('module:assessment_profiles')->name('scales.store');`)
- Test: `tests/Feature/Assessment/ScaleMentionsTest.php`

**Interfaces:**
- Consumes: `DeriveScaleWithLabels::for(Scale, array, string): Scale` (Task 1); `App\Services\Audit\AuditLog::record(string $event, ?Model $subject, ?User $causer, ?string $summary, array $properties)`; `ScalePolicy::create(User)`.
- Produces: resposta `back()` com `session('derived_scale_id' => int)` — o formulário Vue lê `page.props.flash`? **Não:** lê o id pela lista de escalas atualizada, como já faz com «Criar escala personalizada». Para isso o `back()` basta: o Inertia recarrega as props e a lista traz a escala nova. O id da escala devolvida vai também em `with('derivedScaleId', $scale->id)` para o teste poder afirmá-lo.

- [ ] **Step 1: Escrever os testes que falham**

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AuditEvent;
use App\Models\Scale;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScaleMentionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function systemScale(): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5')->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'labels' => ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'],
            'profile_name' => 'Português',
        ], $overrides);
    }

    #[Test]
    public function creates_a_derived_scale_for_the_organization_and_says_which(): void
    {
        $response = $this->actingAs($this->user)
            ->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload());

        $response->assertRedirect();

        $derived = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5 · Português')->firstOrFail();
        $this->assertSame($this->user->personalOrganization()->id, $derived->organization_id);
        $this->assertSame($this->systemScale()->id, $derived->derived_from_scale_id);
        $this->assertSame('Muito insuficiente', $derived->levels()->orderBy('sequence')->first()->label);
        $response->assertSessionHas('derivedScaleId', $derived->id);
    }

    #[Test]
    public function records_an_audit_event_with_the_names_and_no_student_data(): void
    {
        $this->actingAs($this->user)->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload());

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'scale.mentions_customised')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'], $event->properties['labels']);
        $this->assertSame($this->systemScale()->id, $event->properties['derived_from_scale_id']);
    }

    #[Test]
    public function rejects_fewer_than_five_names_repeated_names_and_names_too_long(): void
    {
        $url = "/scales/{$this->systemScale()->ulid}/mencoes";

        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['A', 'B', 'C', 'D']]))->assertSessionHasErrors('labels');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['Bom', 'Insuficiente', 'Suficiente', ' bom ', 'Muito bom']]))->assertSessionHasErrors('labels');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => [str_repeat('x', 65), 'I', 'S', 'B', 'MB']]))->assertSessionHasErrors('labels.0');
        $this->actingAs($this->user)->post($url, $this->payload(['labels' => ['', 'I', 'S', 'B', 'MB']]))->assertSessionHasErrors('labels.0');
    }

    #[Test]
    public function rejects_an_interval_scale(): void
    {
        $percentage = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Percentagem (0 a 100)')->firstOrFail();

        $this->actingAs($this->user)->post("/scales/{$percentage->ulid}/mencoes", $this->payload())->assertSessionHasErrors('labels');
    }

    #[Test]
    public function only_the_owner_of_the_organization_may_customise(): void
    {
        $owner = $this->user;
        $member = User::factory()->create();
        app(CurrentOrganization::class)->runFor($owner->personalOrganization(), function () use ($owner, $member): void {
            $owner->personalOrganization()->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        });

        $this->actingAs($member)
            ->withSession(['current_organization_id' => $owner->personalOrganization()->id])
            ->post("/scales/{$this->systemScale()->ulid}/mencoes", $this->payload())
            ->assertForbidden();
    }
}
```

Se o último teste não conseguir pôr o membro na organização do dono com estas duas linhas, copiar a forma usada em `tests/Feature/Assessment/ScaleTest.php` (procurar «member» / «ownsCurrentOrganization») e ajustar — o que se prova é o 403, não a mecânica da adesão.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=ScaleMentionsTest`
Expected: FAIL com 404/405 (rota inexistente).

- [ ] **Step 3: Pedido**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScaleMentionsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'labels' => ['required', 'array', 'size:5'],
            'labels.*' => ['required', 'string', 'min:1', 'max:64'],
            'profile_name' => ['required', 'string', 'max:120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $labels = array_map(
                fn ($label): string => mb_strtolower(trim((string) $label)),
                (array) $this->input('labels', []),
            );

            if (count($labels) !== count(array_unique($labels))) {
                $validator->errors()->add('labels', 'Duas menções não podem ter o mesmo nome.');
            }
        });
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_values(array_map(fn ($label): string => trim((string) $label), (array) $this->validated('labels')));
    }
}
```

- [ ] **Step 4: Controlador e rota**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScaleMentionsRequest;
use App\Models\Scale;
use App\Services\Assessment\DeriveScaleWithLabels;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * «Personalizar menções»: as cinco menções com os nomes da escola, por perfil.
 * Cria (ou atualiza, enquanto não congelada) uma escala derivada da «Escala 1
 * a 5» — ver DeriveScaleWithLabels. A escala do sistema nunca é tocada.
 */
class ScaleMentionsController extends Controller
{
    public function __construct(protected DeriveScaleWithLabels $derive, protected AuditLog $audit) {}

    public function store(ScaleMentionsRequest $request, Scale $scale): RedirectResponse
    {
        Gate::authorize('create', Scale::class);

        $derived = $this->derive->for($scale, $request->labels(), (string) $request->validated('profile_name'));

        $this->audit->record(
            'scale.mentions_customised',
            $derived,
            $request->user(),
            "Menções da escala «{$derived->name}» definidas pelo utilizador.",
            [
                'scale_id' => $derived->id,
                'derived_from_scale_id' => $derived->derived_from_scale_id,
                'labels' => $request->labels(),
            ],
        );

        return back()->with('derivedScaleId', $derived->id);
    }
}
```

Em `routes/web.php`, logo a seguir à rota `scales.store`:

```php
    Route::post('scales/{scale}/mencoes', [ScaleMentionsController::class, 'store'])
        ->middleware('module:assessment_profiles')->name('scales.mentions.store');
```

(e o `use App\Http\Controllers\ScaleMentionsController;` no topo, por ordem alfabética). `{scale}` resolve por `ulid` (o modelo usa `HasUlids`); confirmar com `php artisan route:list --path=mencoes`.

Confirmado: o helper `user()` é definido controlador a controlador (não está no `Controller` base), por isso o código acima usa `$request->user()`. Também confirmado que não há flash partilhado pelo Inertia nesta app: o `with('derivedScaleId')` serve só ao teste PHP.

- [ ] **Step 5: Correr e ver passar**

Run: `php artisan test --filter=ScaleMentionsTest`
Expected: PASS (5). Depois `php artisan test --filter="ScaleTest|DeriveScaleWithLabelsTest|ScaleMentionsTest"`.

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
vendor/bin/pint --dirty && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Http/Requests/ScaleMentionsRequest.php app/Http/Controllers/ScaleMentionsController.php routes/web.php tests/Feature/Assessment/ScaleMentionsTest.php
git commit -m "feat(scales): POST scales/{scale}/mencoes — personalizar as menções da escala de níveis"
```

---

### Task 3: Perfil novo abre em «Escala 1 a 5»; o formulário recebe os níveis de cada escala

**Files:**
- Modify: `app/Http/Controllers/AssessmentProfileController.php` (`create()` e `formOptions()`)
- Modify: `resources/js/pages/assessment-profiles/Create.vue`, `Edit.vue` (tipo `Option` + prop nova)
- Modify: `resources/js/pages/assessment-profiles/ProfileForm.vue` (prop `defaultScaleId`, tipo `Option.levels`)
- Test: `tests/Feature/Assessment/AssessmentProfileTest.php` (+2), `resources/js/pages/assessment-profiles/ProfileForm.test.ts` (+1)

**Interfaces:**
- Produces (props Inertia de `assessment-profiles/Create` e `Edit`): `scales[].levels: {code: string, label: string}[]` (ordenados por `sequence`; `[]` numa escala de intervalo) e, só em `Create`, `defaultScaleId: number|null` (id da «Escala 1 a 5» do sistema). `ProfileForm` ganha `defaultScaleId?: number | null` e inicia `form.scale_id` com ele quando não há `initial`.

- [ ] **Step 1: Testes PHP que falham** — acrescentar a `AssessmentProfileTest.php`:

```php
    #[Test]
    public function the_create_form_opens_on_the_system_level_scale_and_lists_the_levels_of_each_scale(): void
    {
        $response = $this->actingAs($this->user)->get('/assessment-profiles/create');
        $response->assertOk();

        $props = $response->viewData('page')['props'];
        $levelScale = collect($props['scales'])->firstWhere('label', 'Escala 1 a 5');

        $this->assertSame($levelScale['id'], $props['defaultScaleId']);
        $this->assertSame(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom'], array_column($levelScale['levels'], 'label'));
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($levelScale['levels'], 'code'));
        $this->assertSame([], collect($props['scales'])->firstWhere('label', 'Percentagem (0 a 100)')['levels']);
    }
```

(Usar o `actingAs`/`user` que a classe já tem; se o teste existente abrir a página com `assertInertia`, seguir esse estilo.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter=AssessmentProfileTest::the_create_form_opens`
Expected: FAIL — `defaultScaleId` ausente.

- [ ] **Step 3: Controlador** — em `formOptions()` substituir o mapeamento das escalas por:

```php
            'scales' => Scale::with('levels')->orderByRaw('organization_id IS NOT NULL, name')->get()
                ->map(fn (Scale $scale) => [
                    'id' => $scale->id,
                    'label' => $scale->name,
                    'system' => $scale->organization_id === null,
                    'kind' => $scale->kind,
                    'min_value' => $scale->min_value,
                    'max_value' => $scale->max_value,
                    // As menções, para o diálogo «Personalizar menções» as
                    // mostrar como estão; vazio numa escala de intervalo.
                    'levels' => $scale->levels
                        ->sortBy('sequence')
                        ->map(fn ($level): array => ['code' => (string) $level->code, 'label' => (string) $level->label])
                        ->values()
                        ->all(),
                ]),
```

e em `create()` acrescentar ao `Inertia::render`:

```php
            // Por defeito, a escala com menções (Fraco… Muito Bom): quem não
            // mexer fica com ela. Não é obrigação — é o que evita a pauta só
            // com percentagens de quem criou o perfil sem reparar na escala.
            'defaultScaleId' => Scale::whereNull('organization_id')->where('name', 'Escala 1 a 5')->value('id'),
```

- [ ] **Step 4: Vue** — em `ProfileForm.vue`:
  - no tipo `Option`, acrescentar `levels?: { code: string; label: string }[];`
  - nas props, acrescentar `defaultScaleId?: number | null;` com default `null` no `withDefaults`;
  - no `useForm`, trocar `scale_id: null,` por `scale_id: props.defaultScaleId ?? null,` (só no ramo sem `initial`).

  Em `Create.vue`: tipo `Option` ganha `levels?: { code: string; label: string }[]`, props ganham `defaultScaleId?: number | null`, e `<ProfileForm … :default-scale-id="defaultScaleId" />`. Em `Edit.vue`: só o tipo `Option`.

- [ ] **Step 5: Teste Vue** — em `ProfileForm.test.ts`, mudar `mountForm` para aceitar `props` extra e acrescentar:

```ts
    it('abre com a escala por defeito quando é um perfil novo', () => {
        mount(ProfileForm, {
            props: {
                academicYears: [],
                subjects: [],
                scales: [{ id: 7, label: 'Escala 1 a 5', system: true, kind: 'level', min_value: 1, max_value: 5, levels: [] }],
                submitUrl: '/assessment-profiles',
                method: 'post',
                defaultScaleId: 7,
            },
        });

        expect(profileForm().scale_id).toBe(7);
    });
```

- [ ] **Step 6: Correr tudo e ver passar**

Run: `php artisan test --filter=AssessmentProfileTest && npx vitest run resources/js/pages/assessment-profiles && npx vue-tsc --noEmit && npx eslint resources/js/pages/assessment-profiles`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/AssessmentProfileController.php resources/js/pages/assessment-profiles/ tests/Feature/Assessment/AssessmentProfileTest.php
git commit -m "feat(profiles): perfil novo abre em «Escala 1 a 5»; o formulário recebe as menções de cada escala"
```

---

### Task 4: Botão e diálogo «Personalizar menções» no formulário do perfil

**Files:**
- Modify: `resources/js/pages/assessment-profiles/ProfileForm.vue`
- Test: `resources/js/pages/assessment-profiles/ProfileForm.test.ts` (+3)

**Interfaces:**
- Consumes: `scales[].levels` e `scales[].kind` (Task 3); `POST /scales/{ulid}/mencoes` com `{ labels: string[5], profile_name: string }` (Task 2). **Atenção:** a rota recebe o `ulid` da escala, mas a lista de escalas do formulário só traz `id`. Acrescentar `'ulid' => $scale->ulid` ao mapeamento de `formOptions()` (Task 3, mesmo ficheiro) e `ulid: string` ao tipo `Option`.
- Produces: depois de guardar, `form.scale_id` passa a ser o id da escala derivada. Como o `back()` recarrega as props, a escala nova está em `props.scales`; identifica-se como a escala que não existia antes do pedido e, se nenhuma apareceu (a derivada já existia e foi atualizada em cima), mantém-se a selecionada. **Confirmado:** `HandleInertiaRequests` não partilha flash nenhum, por isso o Vue não lê `derivedScaleId`; não adicionar partilha nova.

- [ ] **Step 1: Testes Vue que falham** — em `ProfileForm.test.ts`:

```ts
const levelScale = {
    id: 7, ulid: 'scale-1-5', label: 'Escala 1 a 5', system: true, kind: 'level', min_value: 1, max_value: 5,
    levels: [
        { code: '1', label: 'Fraco' }, { code: '2', label: 'Insuficiente' }, { code: '3', label: 'Suficiente' },
        { code: '4', label: 'Bom' }, { code: '5', label: 'Muito Bom' },
    ],
};
const percentageScale = { id: 8, ulid: 'scale-pct', label: 'Percentagem (0 a 100)', system: true, kind: 'percentage', min_value: 0, max_value: 100, levels: [] };

describe('personalizar menções', () => {
    it('o botão só está ativo numa escala de níveis', async () => {
        const wrapper = mount(ProfileForm, { props: { academicYears: [], subjects: [], scales: [levelScale, percentageScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 } });
        wrappers.push(wrapper);

        const button = () => wrapper.findAll('button').find((candidate) => candidate.text().includes('Personalizar menções'))!;
        expect(button().attributes('disabled')).toBeUndefined();

        profileForm().scale_id = 8;
        await wrapper.vm.$nextTick();
        expect(button().attributes('disabled')).toBeDefined();
    });

    it('o diálogo abre com as menções atuais e envia as cinco com o nome do perfil', async () => {
        const wrapper = mount(ProfileForm, { props: { academicYears: [], subjects: [], scales: [levelScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 }, attachTo: document.body });
        wrappers.push(wrapper);
        profileForm().name = 'Português';

        await wrapper.findAll('button').find((candidate) => candidate.text().includes('Personalizar menções'))!.trigger('click');
        await wrapper.vm.$nextTick();

        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[id^="mention-"]'));
        expect(inputs.map((input) => input.value)).toEqual(['Fraco', 'Insuficiente', 'Suficiente', 'Bom', 'Muito Bom']);

        // O terceiro useForm é o das menções (perfil, escala personalizada, menções).
        const mentionsForm = mocks.forms[2];
        mentionsForm.labels = ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'];
        (document.querySelector('form[data-test="mentions-form"]') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();

        expect(mentionsForm.post).toHaveBeenCalledWith('/scales/scale-1-5/mencoes', expect.objectContaining({ preserveState: true }));
        expect(mentionsForm.profile_name).toBe('Português');
    });

    it('depois de guardar, o perfil passa a apontar à escala derivada', async () => {
        const derived = { ...levelScale, id: 9, ulid: 'scale-derived', label: 'Escala 1 a 5 · Português', system: false };
        const wrapper = mount(ProfileForm, { props: { academicYears: [], subjects: [], scales: [levelScale], submitUrl: '/assessment-profiles', method: 'post', defaultScaleId: 7 }, attachTo: document.body });
        wrappers.push(wrapper);

        await wrapper.findAll('button').find((candidate) => candidate.text().includes('Personalizar menções'))!.trigger('click');
        const mentionsForm = mocks.forms[2];
        (mentionsForm.post as ReturnType<typeof vi.fn>).mockImplementation(async (_url: string, options: { onSuccess?: () => void }) => {
            await wrapper.setProps({ scales: [levelScale, derived] });
            options.onSuccess?.();
        });

        (document.querySelector('form[data-test="mentions-form"]') as HTMLFormElement).dispatchEvent(new Event('submit', { cancelable: true }));
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        expect(profileForm().scale_id).toBe(9);
    });
});
```

O `MockForm` do ficheiro precisa de `labels?: string[]` e `profile_name?: string` no tipo, e o `useForm` falso tem de devolver `post` como `vi.fn()` (já devolve). Se o `Dialog` não montar no jsdom com `attachTo`, ver como `ProfileForm.test.ts` ou outro teste da pasta `components/ui` abre diálogos e copiar.

- [ ] **Step 2: Correr e ver falhar**

Run: `npx vitest run resources/js/pages/assessment-profiles/ProfileForm.test.ts`
Expected: FAIL — botão inexistente.

- [ ] **Step 3: Implementar no `ProfileForm.vue`**

Script (depois de `submitScale`):

```ts
// ---------------------------------------------------- personalizar menções
//
// AS MENÇÕES DA ESCOLA, POR PERFIL. Por defeito nada muda: quem não carregar
// aqui fica com «Fraco, Insuficiente, Suficiente, Bom, Muito Bom». Guardar
// cria uma escala da organização derivada da do sistema — mesmos níveis,
// bandas e Inovar, só com os nomes novos — e o perfil passa a apontar-lhe.

const selectedScale = computed(() => props.scales.find((scale) => scale.id === form.scale_id) ?? null);

/** Só uma escala de níveis tem menções para personalizar. */
const canCustomiseMentions = computed(
    () => selectedScale.value !== null && selectedScale.value.kind === 'level' && (selectedScale.value.levels?.length ?? 0) > 0,
);

const mentionsDialogOpen = ref(false);

const mentionsForm = useForm<{ labels: string[]; profile_name: string }>({
    labels: [],
    profile_name: '',
});

function openMentionsDialog(): void {
    if (!selectedScale.value) {
        return;
    }

    mentionsForm.clearErrors();
    mentionsForm.labels = (selectedScale.value.levels ?? []).map((level) => level.label);
    mentionsForm.profile_name = form.name;
    mentionsDialogOpen.value = true;
}

function submitMentions(): void {
    const source = selectedScale.value;

    if (!source) {
        return;
    }

    const known = new Set(props.scales.map((scale) => scale.id));
    mentionsForm.profile_name = form.name;

    mentionsForm.post(`/scales/${source.ulid}/mencoes`, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            // A lista de escalas voltou com a derivada: nova (apareceu uma
            // escala que não existia) ou a mesma de antes, atualizada em cima.
            const appeared = props.scales.find((scale) => !known.has(scale.id));

            form.scale_id = appeared?.id ?? source.id;
            mentionsDialogOpen.value = false;
        },
    });
}
```

Ao lado do botão «Criar escala personalizada»:

```html
                        <Button
                            v-if="canManage"
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!canCustomiseMentions"
                            :title="canCustomiseMentions ? undefined : 'Só uma escala de níveis tem menções'"
                            @click="openMentionsDialog"
                        >
                            <Pencil class="size-4" /> Personalizar menções
                        </Button>
```

(importar `Pencil` de `@lucide/vue`; envolver os dois botões num `div class="flex gap-2"`). O diálogo, a seguir ao da escala personalizada:

```html
    <Dialog v-if="canManage" v-model:open="mentionsDialogOpen">
        <DialogContent>
            <form data-test="mentions-form" @submit.prevent="submitMentions">
                <DialogHeader>
                    <DialogTitle>Personalizar menções</DialogTitle>
                    <DialogDescription>
                        As menções que esta escola usa. Os níveis, as percentagens de cada um e a
                        correspondência com o Inovar não mudam.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-3 py-4">
                    <div
                        v-for="(level, index) in selectedScale?.levels ?? []"
                        :key="level.code"
                        class="grid grid-cols-[2rem_1fr] items-center gap-3"
                    >
                        <span class="text-center font-semibold tabular-nums">{{ level.code }}</span>
                        <div class="grid gap-1">
                            <Label :for="`mention-${index}`" class="text-xs text-muted-foreground">
                                Lapispro: {{ level.label }}
                            </Label>
                            <Input :id="`mention-${index}`" v-model="mentionsForm.labels[index]" maxlength="64" />
                            <InputError :message="mentionsForm.errors[`labels.${index}`]" />
                        </div>
                    </div>
                    <InputError :message="mentionsForm.errors.labels" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="mentionsDialogOpen = false">Cancelar</Button>
                    <Button type="submit" :disabled="mentionsForm.processing">Guardar</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
```

`mentionsForm.errors` é `Record<string, string>`; se o TypeScript recusar o índice dinâmico, tipar como `(mentionsForm.errors as Record<string, string>)[...]`.

- [ ] **Step 4: Correr e ver passar**

Run: `npx vitest run resources/js/pages/assessment-profiles/ProfileForm.test.ts && npx vue-tsc --noEmit && npx eslint --fix resources/js/pages/assessment-profiles`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/assessment-profiles/ app/Http/Controllers/AssessmentProfileController.php
git commit -m "feat(profiles): «Personalizar menções» — diálogo com as cinco menções, escala derivada selecionada ao guardar"
```

---

### Task 5: A nota na Pauta quando a escala não tem menções

**Files:**
- Modify: `app/Http/Controllers/EvaluationSheetController.php` (`show`, bloco `'schoolClass' => [...]`)
- Modify: `resources/js/pages/evaluation-sheets/Show.vue`
- Test: `tests/Feature/Assessment/EvaluationSheetControllerTest.php` (+2), `resources/js/pages/evaluation-sheets/Show.test.ts` (+3)

**Interfaces:**
- Produces: `schoolClass.profile_edit_url: string | null` — a URL `assessment-profiles/{ulid}/edit` do perfil da turma quando `Gate::allows('update', $profile)`, senão `null`. A nota aparece quando `has_profile && scaleBands.length === 0`.

- [ ] **Step 1: Testes PHP que falham** — em `EvaluationSheetControllerTest.php`:

```php
    #[Test]
    public function a_class_on_a_scale_without_mentions_gets_the_link_to_fix_its_profile(): void
    {
        $teacher = $this->seedDemo();
        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $profile = $class->profileVersion->profile;
            $domains = $profile->currentVersion->domains()->with('domain')->get()
                ->map(fn ($row): array => ['name' => $row->domain->name, 'weight' => (float) $row->weight_percent])->all();
            app(\App\Services\Assessment\ProfileBuilder::class)->update(
                $profile,
                ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
                \App\Models\Scale::where('name', 'Percentagem (0 a 100)')->firstOrFail()->id,
                $domains,
                $profile->gradeLevels->pluck('grade_level')->all(),
            );
            $version = app(\App\Services\Assessment\ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), auth()->user() ?? \App\Models\User::first());
            $class->update(['assessment_profile_version_id' => $version->id]);
        });

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertSame([], $props['scaleBands']);
        $this->assertStringEndsWith('/edit', $props['schoolClass']['profile_edit_url']);
        $this->assertStringContainsString('/assessment-profiles/', $props['schoolClass']['profile_edit_url']);
    }

    #[Test]
    public function a_class_on_the_level_scale_has_bands_and_no_fix_link(): void
    {
        $teacher = $this->seedDemo();

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertNotEmpty($props['scaleBands']);
        $this->assertNull($props['schoolClass']['profile_edit_url']);
    }
```

Se `activate()` precisar do professor como ator, usar `$teacher` (passá-lo ao closure). O trait `tests/Support/MigratesPercentageClassToLevels.php` faz exatamente esta troca em `activateVersionOn()`; pode ser usado em vez do bloco acima se o teste conseguir o `$this->teacher`.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test --filter="EvaluationSheetControllerTest::a_class_on"`
Expected: FAIL — `profile_edit_url` ausente.

- [ ] **Step 3: Controlador** — depois de `$scale = $class->profileVersion?->scale()->with('levels')->first();`:

```php
        // A ligação para resolver «só percentagens»: só quando a escala não tem
        // menções E quem vê pode editar o perfil. Quem não pode vê a nota sem
        // ligação (Show.vue). Nunca numa pauta guardada — é uma fotografia.
        $profile = $class->profileVersion?->profile;
        $hasBands = $scale !== null && $scale->levels->contains(
            fn ($level): bool => $level->band_min_normalized !== null && $level->band_max_normalized !== null,
        );
        $profileEditUrl = $profile !== null && ! $hasBands && Gate::allows('update', $profile)
            ? route('assessment-profiles.edit', $profile)
            : null;
```

e em `'schoolClass' => [...]` acrescentar `'profile_edit_url' => $profileEditUrl,`. (`Gate` já é importado? confirmar o `use Illuminate\Support\Facades\Gate;`.)

- [ ] **Step 4: Testes Vue que falham** — em `Show.test.ts`, um `describe('evaluation-sheets/Show — escala sem menções')`:

```ts
describe('evaluation-sheets/Show — escala sem menções', () => {
    it('avisa, com ligação ao perfil, quando a escala não tem menções e o perfil é editável', () => {
        const props = baseProps();
        props.scaleBands = [];
        props.schoolClass = { ...props.schoolClass, scale_name: 'Percentagem (0 a 100)', profile_edit_url: '/assessment-profiles/profile-1/edit' };
        const wrapper = mount(Show, { props });

        const note = wrapper.find('[data-test="no-mentions-note"]');
        expect(note.exists()).toBe(true);
        expect(note.text()).toContain('Percentagem (0 a 100)');
        expect(note.text()).toContain('só percentagens');
        expect(note.find('a').attributes('href')).toBe('/assessment-profiles/profile-1/edit');
        expect(note.classes()).toContain('print-hide');
    });

    it('avisa sem ligação quando quem vê não pode editar o perfil', () => {
        const props = baseProps();
        props.scaleBands = [];
        props.schoolClass = { ...props.schoolClass, profile_edit_url: null };
        const wrapper = mount(Show, { props });

        const note = wrapper.find('[data-test="no-mentions-note"]');
        expect(note.exists()).toBe(true);
        expect(note.find('a').exists()).toBe(false);
    });

    it('não avisa quando a escala tem menções', () => {
        const wrapper = mount(Show, { props: baseProps() });

        expect(wrapper.find('[data-test="no-mentions-note"]').exists()).toBe(false);
    });
});
```

No tipo `schoolClass` das props de `Show.vue` acrescentar `profile_edit_url?: string | null;` (e no `baseProps()` do teste, se o TypeScript o exigir, `profile_edit_url: null`).

- [ ] **Step 5: `Show.vue`** — script: `const scaleHasMentions = computed(() => props.scaleBands.length > 0);`. Template, imediatamente antes de `<EvaluationSheetViewControls`:

```html
        <!-- SÓ PERCENTAGENS, E PORQUÊ. Uma escala sem níveis com bandas (0 a 20,
             percentagem, personalizada de intervalo) não tem menção nenhuma a
             mostrar — a pauta não esconde nada, não há nada. A nota diz isso e
             aponta para onde se resolve. Fora do papel e nunca numa fotografia. -->
        <p
            v-if="sheet && schoolClass.has_profile && !scaleHasMentions"
            data-test="no-mentions-note"
            class="print-hide rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900"
        >
            Esta escala<template v-if="schoolClass.scale_name"> («{{ schoolClass.scale_name }}»)</template> não tem
            menções qualitativas, por isso a pauta mostra só percentagens. Para ter Suficiente, Bom…, edite o
            perfil de avaliação e escolha a «Escala 1 a 5».
            <Link v-if="schoolClass.profile_edit_url" :href="schoolClass.profile_edit_url" class="ml-1 font-medium underline">
                Abrir o perfil
            </Link>
        </p>
```

- [ ] **Step 6: Correr tudo e ver passar**

Run: `php artisan test --filter=EvaluationSheetControllerTest && npx vitest run resources/js/pages/evaluation-sheets/Show.test.ts resources/js/lib/printStyles.test.ts && npx vue-tsc --noEmit && npx eslint resources/js/pages/evaluation-sheets`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/EvaluationSheetController.php resources/js/pages/evaluation-sheets/Show.vue resources/js/pages/evaluation-sheets/Show.test.ts tests/Feature/Assessment/EvaluationSheetControllerTest.php
git commit -m "feat(pauta): nota «só percentagens» quando a escala do perfil não tem menções, com ligação ao perfil"
```

---

### Task 6: A pauta de um perfil com menções personalizadas escreve os nomes da escola (prova de ponta a ponta)

**Files:**
- Test: `tests/Feature/Assessment/EvaluationSheetControllerTest.php` (+1)

**Interfaces:**
- Consumes: `DeriveScaleWithLabels` (Task 1), `ProfileBuilder::update`, `ActivateProfileVersion::activate` (existentes), `MigrateClassProfile::migrate` (existente).

- [ ] **Step 1: O teste**

```php
    #[Test]
    public function a_profile_on_a_derived_scale_shows_the_school_mentions_and_freezes_the_scale_on_activation(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function () use ($teacher): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $profile = $class->profileVersion->profile;
            $system = \App\Models\Scale::where('name', 'Escala 1 a 5')->firstOrFail();
            $derived = app(\App\Services\Assessment\DeriveScaleWithLabels::class)
                ->for($system, ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'], $profile->name);

            $domains = $profile->currentVersion->domains()->with('domain')->get()
                ->map(fn ($row): array => ['name' => $row->domain->name, 'weight' => (float) $row->weight_percent])->all();
            app(\App\Services\Assessment\ProfileBuilder::class)->update(
                $profile,
                ['subject_id' => $profile->subject_id, 'name' => $profile->name, 'description' => $profile->description],
                $derived->id,
                $domains,
                $profile->gradeLevels->pluck('grade_level')->all(),
            );
            $version = app(\App\Services\Assessment\ActivateProfileVersion::class)->activate($profile->refresh()->draftVersion(), $teacher);

            $this->assertNotNull($derived->refresh()->frozen_at, 'A escala derivada congela no primeiro uso por uma versão ativa.');

            app(\App\Services\Assessment\MigrateClassProfile::class)->migrate(
                SchoolClass::whereKey($class->id)->firstOrFail(),
                $version,
                'Menções da escola.',
                $teacher,
            );
        });

        $props = $this->props($this->open($teacher, $this->classUlid($teacher)));

        $this->assertSame(['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom'], array_column($props['scaleBands'], 'label'));
        $labels = collect($props['sheet']['students'])
            ->flatMap(fn (array $student): array => array_column($student['domains'], 'scale_level_label'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $this->assertNotEmpty($labels);
        $this->assertSame([], array_diff($labels, ['Muito insuficiente', 'Insuficiente', 'Suficiente', 'Bom', 'Muito bom']));
        $this->assertNotContains('Fraco', $labels);
        $this->assertNull($props['schoolClass']['profile_edit_url']);
    }
```

Se a demo não tiver classificações, o `migrate` ainda funciona (não há propostas a refrescar). Se `MigrateClassProfile::migrate` exigir algo mais (um período), copiar de `tests/Support/MigratesPercentageClassToLevels.php::migrateToTheLevelVersion`.

- [ ] **Step 2: Correr e ver passar** (deve passar à primeira; se falhar, é um defeito real a corrigir nas tarefas 1 a 5, nunca no teste)

Run: `php artisan test --filter=EvaluationSheetControllerTest::a_profile_on_a_derived_scale`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Assessment/EvaluationSheetControllerTest.php
git commit -m "test(pauta): uma escala derivada escreve as menções da escola e congela ao ativar"
```

---

### Task 7: Versão, changelog, documentação e verificação completa

**Files:**
- Modify: `config/app.php` (`'version' => '0.193.0'`), `CHANGELOG.md`, `docs/domain-model.md` (§2.3, uma nota), `docs/status.md` (linha do perfil de avaliação, se existir)

- [ ] **Step 1: Versão e changelog** — `config/app.php`: `'version' => '0.193.0',`. No topo do `CHANGELOG.md`, antes de `## [0.192.0]`:

```markdown
## [0.193.0] — 2026-11-11

> **As menções da escola, por perfil — e «Escala 1 a 5» por defeito.** A
> apreciação qualitativa da pauta só existe numa escala de níveis, e um perfil
> criado em «Percentagem» mostrava só números sem dizer porquê. Os perfis novos
> passam a abrir em «Escala 1 a 5»; a Pauta de uma turma cuja escala não tem
> menções diz-o numa linha, com ligação ao perfil; e o professor pode
> «Personalizar menções» — dar aos cinco níveis os nomes que a sua escola usa
> («Muito insuficiente» em vez de «Fraco»). Por defeito nada muda: quem não
> mexer continua a ver Fraco, Insuficiente, Suficiente, Bom, Muito Bom. Os
> níveis, as bandas de percentagem e os códigos Inovar não são editáveis. Sem
> migrations.

### Adicionado

- `DeriveScaleWithLabels`: escala da organização derivada da «Escala 1 a 5»
  (`derived_from_scale_id`), níveis copiados campo a campo, só o nome muda;
  copy-on-write depois de congelada, como o modelo de domínio já previa.
- `POST scales/{scale}/mencoes` e o diálogo «Personalizar menções» no
  formulário do perfil.
- Perfil novo abre com «Escala 1 a 5»; nota na Pauta quando a escala não tem
  menções.
```

- [ ] **Step 2: Documentação** — em `docs/domain-model.md`, no fim de «#### `scales`» (§2.3), acrescentar:

```markdown
> **0.193.0 — menções personalizadas.** `DeriveScaleWithLabels` cria uma escala da organização derivada da «Escala 1 a 5» (`derived_from_scale_id`), com os cinco `scale_levels` copiados campo a campo e só `label` diferente. É a única forma de um utilizador obter menções próprias; bandas, códigos e Inovar nunca são editáveis. Enquanto não congelada, os nomes editam-se em cima; congelada, cria-se outra derivada da mesma origem.
```

- [ ] **Step 3: Verificação completa**

```bash
export PATH="/c/Users/PCUser/.config/herd/bin/php84:$PATH"
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=1G
php artisan test --filter="ReleaseVersion|Scale|AssessmentProfile|EvaluationSheet|DeriveScale"
npm run build && npx vue-tsc --noEmit && npx eslint resources/js && npx vitest run
```

Depois, os testes novos de BD em MySQL (ver `tests/Concerns/ConnectsToMysqlServer.php` e a memória `lapispro-testes-sqlite-escondem-checks`): `php artisan test --filter="DeriveScaleWithLabelsTest|ScaleMentionsTest"` com `DB_CONNECTION` apontado ao MySQL local.

- [ ] **Step 4: Commit e PR**

```bash
git add config/app.php CHANGELOG.md docs/domain-model.md docs/status.md
git commit -m "chore(release): 0.193.0 — menções personalizadas da escala e «Escala 1 a 5» por defeito"
git push -u origin feat/scale-custom-mentions
gh pr create --base main --title "Menções personalizadas da escala e «Escala 1 a 5» por defeito (0.193.0)" --body-file -
```

QA manual no browser isolado (`lapispro-qa-browser-isolado`): criar um perfil novo (abre em 1 a 5), «Personalizar menções», guardar, ativar, ligar uma turma, abrir a pauta e confirmar «Muito insuficiente»; abrir a pauta de uma turma em Percentagem e ver a nota com a ligação.

---

## Self-review

- **Cobertura da spec:** §5 dados/regras → Task 1; §7 servidor → Task 2; §6 formulário (pré-seleção, botão, diálogo, seleção ao guardar) → Tasks 3 e 4; §8 nota na Pauta → Task 5; §9 migração → sem código (coberto pelo Task 6 que exercita o fluxo existente); §10 testes → cada task; Inovar da derivada → `inovar_code` copiado e afirmado no Task 1; congelamento ao ativar → Task 6; §11 entrega → Task 7.
- **Placeholders:** nenhum «TBD»; os pontos incertos (nome do flash do Inertia, mecânica de adesão de um membro no teste de 403, ator do `activate`) estão identificados com a alternativa a seguir.
- **Consistência de tipos:** `DeriveScaleWithLabels::for(Scale $source, array $labels, string $profileName): Scale` é usado com essa assinatura nas Tasks 2 e 6; `scales[].levels: {code,label}[]` e `scales[].ulid` definidos na Task 3 e consumidos na Task 4; `schoolClass.profile_edit_url: string|null` definido e consumido na Task 5; a rota `scales/{scale}/mencoes` usa o `ulid` tanto no controlador (route model binding) como no Vue.
