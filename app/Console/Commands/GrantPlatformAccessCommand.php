<?php

namespace App\Console\Commands;

use App\Enums\PlatformAccess;
use App\Models\User;
use App\Support\Platform\PlatformAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:access {email : User email address} {level : view, manage, or revoke}')]
#[Description('Grant or revoke platform administration access for a user')]
class GrantPlatformAccessCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $level = strtolower(trim((string) $this->argument('level')));

        if (! in_array($level, ['view', 'manage', 'revoke'], true)) {
            $this->error('Level must be view, manage, or revoke.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user found for [{$email}].");

            return self::FAILURE;
        }

        $access = match ($level) {
            'view' => PlatformAccess::View,
            'manage' => PlatformAccess::Manage,
            default => null,
        };

        $user->forceFill(['platform_access' => $access])->save();

        PlatformAudit::record(null, 'Updated platform access', [
            'action' => 'platform.access.set',
            'target_user_id' => $user->id,
            'level' => $level,
        ]);

        if ($access === null) {
            $this->info("Revoked platform access for {$user->email}.");
        } else {
            $this->info("Granted platform {$access->value} access to {$user->email}.");
        }

        return self::SUCCESS;
    }
}
