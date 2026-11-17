<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Credits\CreditLedger;
use App\Enums\CreditTransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // This app sends no email, so a new account counts as verified straight
        // away. Otherwise a reviewer could never get past the verification screen.
        $user->forceFill(['email_verified_at' => now()])->save();

        app(CreditLedger::class)->add(
            $user,
            CreditTransactionType::SignupBonus,
            (int) config('pixelforge.credits.signup_bonus_nano'),
            'Welcome credit',
        );

        return $user;
    }
}
