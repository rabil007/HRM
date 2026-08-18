<?php

namespace App\Support\SavedViews;

use App\Models\SavedView;

final class DeleteSavedView
{
    public function handle(SavedView $view): void
    {
        $view->delete();
    }
}
