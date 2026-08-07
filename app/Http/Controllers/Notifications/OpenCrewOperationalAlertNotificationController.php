<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\CrewOperationalAlertRecipient;
use App\Support\Companies\ActivateCompanySession;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenCrewOperationalAlertNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        CrewOperationalAlertRecipient $recipient,
        ActivateCompanySession $activateCompany,
        ResolveCrewOperationalAlertUrl $resolveUrl,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null && (int) $recipient->user_id === (int) $user->id, 404);

        $alert = $recipient->alert;
        abort_unless($alert !== null, 404);

        $activateCompany->handle($user, (int) $recipient->company_id, $request);

        if ($recipient->read_at === null) {
            $recipient->update(['read_at' => now()]);
        }

        $url = $resolveUrl->forUser($user, $alert);

        if ($url !== null) {
            return redirect()->to($url);
        }

        return redirect()->route('dashboard');
    }
}
