<?php

namespace App\Actions\Fortify;

use App\Actions\Organizations\CreatePersonalOrganization;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
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

            $this->createPersonalOrganization->create($user);

            return $user;
        });
    }
}
