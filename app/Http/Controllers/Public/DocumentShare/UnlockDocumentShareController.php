<?php

namespace App\Http\Controllers\Public\DocumentShare;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnlockDocumentShareController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DocumentShareService $shares,
    ): RedirectResponse {
        $share = $shares->findByToken($token);
        abort_if($share === null, 404);
        $shares->assertAccessible($share);

        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $shares->unlock($share, $validated['password']);

        return redirect()->to($shares->shareUrl($share));
    }
}
