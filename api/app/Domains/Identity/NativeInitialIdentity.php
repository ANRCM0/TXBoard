<?php

namespace App\Domains\Identity;

use App\Domains\Identity\Internal\InitialIdentity;
use App\Domains\Installation\NativePreparationInput;
use App\Domains\Installation\NativePreparationSteps;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NativeInitialIdentity implements InitialIdentity
{
    public function prepare(string $key, #[\SensitiveParameter] array $input): array
    {
        NativePreparationInput::validate($input, 'identity');
        if (filter_var($input['email'], FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('NATIVE_PREPARATION_INPUT_INVALID');
        return app(NativePreparationSteps::class)->execute('identity', $key, $input, function () use ($input): array {
            if (User::query()->lockForUpdate()->exists()) throw new RuntimeException('NATIVE_PREPARATION_IDENTITY_NOT_EMPTY');
            $user = User::withoutEvents(fn (): User => User::query()->create([
                'email' => strtolower($input['email']), 'password' => password_hash($input['password'], PASSWORD_DEFAULT),
                'uuid' => '', 'token' => '', 'plan_id' => null, 'group_id' => null,
                'transfer_enable' => 0, 'u' => 0, 'd' => 0, 'balance' => 0, 'commission_balance' => 0,
                'expired_at' => null, 'next_reset_at' => null, 'last_login_at' => null,
                'banned' => false, 'is_admin' => true, 'is_staff' => false,
            ]));
            foreach (array_unique($input['permissions']) as $permission) {
                DB::table('tx_internal_actor_grant')->insert(['user_id' => $user->id, 'permission' => $permission, 'created_at' => time()]);
            }
            return ['administrator_id' => (string) $user->id];
        });
    }
}
