<?php

namespace MasterCore\Auth\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * Validate the new secret, update the host user, and remove database sessions for that identity.
     *
     * @throws ValidationException
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        // The host owns sessions; invalidate rows only when the selected store is the stock DB driver.
        if (config('session.driver') === 'database' && DB::getSchemaBuilder()->hasTable('sessions')) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', (string) $user->getAuthIdentifier())
                ->delete();
        }
    }
}
