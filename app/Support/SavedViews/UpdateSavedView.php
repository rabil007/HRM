<?php

namespace App\Support\SavedViews;

use App\Models\SavedView;
use Illuminate\Support\Facades\DB;

final class UpdateSavedView
{
    public function handle(SavedView $view, ?string $name, ?bool $isDefault): SavedView
    {
        return DB::transaction(function () use ($view, $name, $isDefault): SavedView {
            if ($name !== null) {
                $view->name = trim($name);
            }

            if ($isDefault === true) {
                SavedView::query()
                    ->where('user_id', $view->user_id)
                    ->where('company_id', $view->company_id)
                    ->where('page_key', $view->page_key)
                    ->where('is_default', true)
                    ->whereKeyNot($view->id)
                    ->update(['is_default' => false]);

                $view->is_default = true;
            } elseif ($isDefault === false) {
                $view->is_default = false;
            }

            $view->save();

            return $view;
        });
    }
}
