<?php

namespace App\Actions\Fortify;

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(protected CreatePersonalOrganization $createPersonalOrganization) {}

    /**
     * Validate and create a newly registered user.
     *
     * The user and their personal organization are created in one transaction:
     * an account with no organization would authenticate but have no tenant to
     * resolve, and every request would then 403.
     *
     * A ACEITAÇÃO DOS TERMOS É ESCRITA AQUI, NA MESMA TRANSAÇÃO, e a versão vem
     * de `LegalDocuments` e não do pedido. Um campo de formulário a dizer que
     * versão foi aceite é um campo que o cliente pode alterar — e uma conta
     * que afirma ter aceite uma versão que nunca esteve em vigor prova menos
     * do que não afirmar nada.
     *
     * NÃO SE GUARDA IP NEM NAVEGADOR. Ver a migração
     * `add_terms_acceptance_to_users` para a razão.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->forceFill([
                'terms_version' => LegalDocuments::termsVersion(),
                'terms_accepted_at' => now(),
            ])->save();

            $this->createPersonalOrganization->create($user);

            return $user;
        });
    }
}
