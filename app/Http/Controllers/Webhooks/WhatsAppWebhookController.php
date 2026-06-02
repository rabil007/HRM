<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request): Response|SymfonyResponse|string
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        return response()->noContent();
    }

    private function verify(Request $request): Response|SymfonyResponse|string
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode !== 'subscribe' || $challenge === '') {
            return response('Forbidden', 403);
        }

        $storedToken = WhatsAppSetting::current()->webhook_verify_token;

        if (! filled($storedToken) || ! hash_equals((string) $storedToken, $token)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }
}
