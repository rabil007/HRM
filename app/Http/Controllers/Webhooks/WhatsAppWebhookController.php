<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSetting;
use App\Support\WhatsApp\MetaWebhookSignature;
use App\Support\WhatsApp\ProcessWhatsAppWebhook;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WhatsAppWebhookController extends Controller
{
    private const int MAX_ATTEMPTS_PER_MINUTE = 120;

    public function __construct(
        private MetaWebhookSignature $signature,
        private ProcessWhatsAppWebhook $processor,
        private RateLimiter $rateLimiter,
    ) {}

    public function __invoke(Request $request): Response|SymfonyResponse|string
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        $settings = WhatsAppSetting::activeWebhookIntegration();

        if (
            $settings === null
            || ! $settings->canAuthenticateWebhook()
            || ! $this->signature->isValid(
                (string) $request->getContent(),
                $request->header('X-Hub-Signature-256'),
                $settings->app_secret,
            )
        ) {
            return response('Forbidden', 403);
        }

        $rateLimitKey = 'whatsapp-webhook:'.hash('sha256', (string) $request->ip());

        if ($this->rateLimiter->tooManyAttempts($rateLimitKey, self::MAX_ATTEMPTS_PER_MINUTE)) {
            return response('Too Many Requests', 429)
                ->header('Retry-After', (string) $this->rateLimiter->availableIn($rateLimitKey));
        }

        $this->rateLimiter->hit($rateLimitKey, 60);

        $this->processor->handle($request->json()->all(), $settings);

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
