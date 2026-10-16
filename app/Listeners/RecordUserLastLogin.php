<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Auth\RememberSession;
use Illuminate\Auth\Events\Login;

class RecordUserLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ($event->remember) {
            RememberSession::mark();
        } else {
            RememberSession::forget();
        }

        if (request()->hasSession()) {
            request()->session()->put('auth.password_confirmed_at', time());
        }

        $event->user->forceFill([
            'last_login_at' => now(),
        ])->save();
    }
}
