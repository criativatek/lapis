<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;

/**
 * O operador nomeado de uma decisão sobre as métricas de utilização: a
 * auditoria tem de registar quem a tomou. Classificar exige acesso ao painel;
 * ligar e desligar a recolha exige a autorização SEPARADA de controlo.
 */
trait ResolvesUsageOperator
{
    private function resolveOperator(bool $control = false): ?User
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

        if ($control && ! $operator->canControlUsageCollection()) {
            $this->components->error('Esse utilizador não tem a autorização de controlar a recolha (consultar o painel não chega).');

            return null;
        }

        if (! $control && ! $operator->canViewUsageInsights()) {
            $this->components->error('Esse utilizador não é administrador com acesso às métricas de utilização.');

            return null;
        }

        return $operator;
    }
}
