<?php

namespace App\Support\Documents;

use App\Models\DocumentGenerationRun;
use App\Models\User;
use App\Notifications\DocumentGenerationFinishedWebPushNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class NotifyDocumentGenerationFinished
{
    public function handle(DocumentGenerationRun $run): void
    {
        $userId = $run->triggered_by;

        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        $run->loadMissing(['template:id,name', 'templateVersion:id,version']);

        try {
            Notification::send($user, DocumentGenerationFinishedWebPushNotification::fromRun($run));
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('Document generation finished push failed', [
                'company_id' => $run->company_id,
                'run_id' => $run->id,
                'user_id' => $user->id,
            ]);
        }
    }
}
