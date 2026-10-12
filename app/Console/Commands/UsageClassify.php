<?php

namespace App\Console\Commands;

use App\Actions\Usage\SetUsageClassification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Commercial\CurrentSubscriptions;
use App\Support\Usage\UsageClassification;
use Illuminate\Console\Command;

/**
 * Lista e classifica as organizações para as métricas de utilização, uma a uma
 * e com rasto, pela mesma `SetUsageClassification` que o ecrã usa. Não infere
 * nada: quem decide é o operador.
 */
class UsageClassify extends Command
{
    protected $signature = 'lapis:usage-classify
        {organization? : ULID da organização}
        {classification? : unclassified, real, test ou internal}
        {--list : Só listar (não escreve nada)}
        {--operator= : Email do administrador com acesso às métricas de utilização}
        {--note= : Porquê, nas palavras do operador}';

    protected $description = 'List organizations or classify one for the usage metrics (real, test, internal)';

    public function handle(SetUsageClassification $setClassification, CurrentSubscriptions $subscriptions): int
    {
        if ($this->option('list')) {
            return $this->list($subscriptions);
        }

        $ulid = $this->argument('organization');
        $value = $this->argument('classification');

        if (! is_string($ulid) || ! is_string($value)) {
            $this->components->error('Indique {organization} {classification}, ou use --list.');

            return self::FAILURE;
        }

        $classification = UsageClassification::tryFrom($value);

        if ($classification === null) {
            $this->components->error('Classificação inválida. Valores: '.implode(', ', array_map(fn (UsageClassification $c): string => $c->value, UsageClassification::cases())).'.');

            return self::FAILURE;
        }

        $operator = $this->resolveOperator();

        if ($operator === null) {
            return self::FAILURE;
        }

        $organization = Organization::query()->where('ulid', $ulid)->first();

        if ($organization === null) {
            $this->components->error('Não existe organização com esse ULID.');

            return self::FAILURE;
        }

        $previous = (string) $organization->usage_classification;
        $note = $this->option('note');
        $setClassification->set($organization, $operator, $classification, is_string($note) ? $note : null);

        $this->components->info($previous === $classification->value
            ? 'Sem alterações: já estava como '.$classification->label().'.'
            : 'Organização classificada como '.$classification->label().'.');

        return self::SUCCESS;
    }

    private function list(CurrentSubscriptions $subscriptions): int
    {
        $organizations = Organization::query()->withCount('members')->orderBy('id')->get();
        $plans = $subscriptions->for($organizations->pluck('id')->all());

        $this->table(
            ['ULID', 'Tipo', 'Nome', 'Criada em', 'Plano', 'Marca comercial «teste» (não decide nada aqui)', 'Classificação', 'Membros'],
            $organizations->map(fn (Organization $organization): array => [
                $organization->ulid,
                $organization->type->value,
                $organization->name,
                $organization->created_at?->toDateTimeString() ?? '—',
                $plans->get($organization->id)?->plan->name ?? '—',
                $organization->is_test_account ? 'sim' : 'não',
                (string) $organization->usage_classification,
                $organization->members_count,
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function resolveOperator(): ?User
    {
        $email = $this->option('operator');

        if (! is_string($email) || trim($email) === '') {
            $this->components->error('Falta --operator=<email>. A auditoria tem de registar quem tomou a decisão.');

            return null;
        }

        $operator = User::query()->where('email', trim($email))->first();

        if ($operator === null) {
            $this->components->error('Não existe utilizador com esse email.');

            return null;
        }

        if (! $operator->canViewUsageInsights()) {
            $this->components->error('Esse utilizador não é administrador com acesso às métricas de utilização.');

            return null;
        }

        return $operator;
    }
}
