<?php

namespace App\Http\Controllers;

use App\Http\Requests\Favorites\DestroyFavoriteRequest;
use App\Http\Requests\Favorites\StoreFavoriteRequest;
use App\Support\Navigation\AddNavigationFavorite;
use App\Support\Navigation\RemoveNavigationFavorite;
use Illuminate\Http\RedirectResponse;

class NavigationFavoriteController extends Controller
{
    public function store(StoreFavoriteRequest $request, AddNavigationFavorite $add): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $add->handle($user, $request->destinationKey());

        return back();
    }

    public function destroy(DestroyFavoriteRequest $request, RemoveNavigationFavorite $remove): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $remove->handle($user, $request->destinationKey());

        return back();
    }
}
