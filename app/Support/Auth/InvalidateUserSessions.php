<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rotate remembered credentials and drop indexed database sessions.
 *
 * Used after security-sensitive account changes (password change/reset, and
 * by {@see RevokeDisabledUserAccess} when status leaves `active`).
 *
 * File, cookie, Redis, array, and similar stores are not bulk-deleted.
 * Laravel's `AuthenticateSession` middleware still rejects leftover sessions
 * on the next request when the password hash no longer matches. Do not guess
 * PHP session file paths.
 */
final class InvalidateUserSessions
{
    public function handle(User $user, bool $keepCurrentSession = false): void
    {
        $this->cycleRememberToken($user);
        $this->invalidateDatabaseSessions(
            $user,
            $keepCurrentSession ? $this->currentSessionId() : null,
        );
    }

    /**
     * Keep the acting user's current session when they change their own password.
     * Admin/reset paths are guests or a different user, so every session is dropped.
     */
    public function handleForPasswordChange(User $user): void
    {
        $keepCurrentSession = Auth::guard('web')->hasUser()
            && (int) Auth::guard('web')->id() === (int) $user->id;

        $this->handle($user, $keepCurrentSession);
    }

    private function cycleRememberToken(User $user): void
    {
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();
    }

    private function invalidateDatabaseSessions(User $user, ?string $exceptSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $table = (string) config('session.table', 'sessions');
        $connection = config('session.connection');

        if (! Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn($table, 'user_id')) {
            return;
        }

        $query = DB::connection($connection)
            ->table($table)
            ->where('user_id', $user->id);

        if (is_string($exceptSessionId) && $exceptSessionId !== '') {
            $query->where('id', '!=', $exceptSessionId);
        }

        $query->delete();
    }

    private function currentSessionId(): ?string
    {
        $request = request();

        if (! $request instanceof Request || ! $request->hasSession()) {
            return null;
        }

        $sessionId = $request->session()->getId();

        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }
}
