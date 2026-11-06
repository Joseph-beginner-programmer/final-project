<?php

namespace App\Actions\MasterData;

use App\Enums\UserRole;
use App\Models\User;

class SaveUserAction
{
    /**
     * @param  array{name: string, email: string, role: UserRole, is_active: bool, password: ?string}  $fields
     */
    public function handle(array $fields, ?User $user = null): User
    {
        $password = $fields['password'];
        unset($fields['password']);

        $user ??= new User();
        $user->fill($fields);

        if ($password !== null) {
            $user->password = $password;
        }

        if (! $user->exists) {
            $user->email_verified_at = now();
        }

        $user->save();

        return $user;
    }
}
