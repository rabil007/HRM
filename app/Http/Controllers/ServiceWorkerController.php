<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServiceWorkerController extends Controller
{
    /**
     * Serve the root-scoped push service worker.
     *
     * We intentionally serve the lightweight push handler (public/service-worker.js)
     * rather than the VitePWA/Workbox build artifact. The build worker precaches
     * hashed production assets that 404 under `npm run dev`, which leaves the
     * worker unhealthy so FCM can accept pushes (201) while the browser never
     * shows a notification.
     */
    public function __invoke(): BinaryFileResponse|Response
    {
        $path = public_path('service-worker.js');

        if (! is_file($path)) {
            return response('Service worker is not available.', 404)
                ->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
