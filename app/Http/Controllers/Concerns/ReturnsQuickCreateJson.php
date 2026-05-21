<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait ReturnsQuickCreateJson
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    protected function findExistingQuickCreate(
        string $modelClass,
        string $labelColumn,
        string $labelValue,
        array $attributes = [],
    ): ?Model {
        $normalized = mb_strtolower(trim($labelValue));

        if ($normalized === '') {
            return null;
        }

        $query = $modelClass::query();

        foreach ($attributes as $column => $value) {
            $query->where($column, $value);
        }

        return $query
            ->whereRaw('LOWER('.$labelColumn.') = ?', [$normalized])
            ->first();
    }

    protected function quickCreateJsonResponse(Model $model, string $labelAttribute = 'name'): JsonResponse
    {
        $label = (string) $model->getAttribute($labelAttribute);

        return response()->json([
            'id' => $model->getKey(),
            'label' => $label,
            $labelAttribute => $label,
        ]);
    }

    protected function storeRedirectOrQuickCreateJson(
        Request $request,
        Model $model,
        RedirectResponse $redirect,
        string $labelAttribute = 'name',
    ): JsonResponse|RedirectResponse {
        if ($request->wantsJson()) {
            return $this->quickCreateJsonResponse($model, $labelAttribute);
        }

        return $redirect;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    protected function createOrReturnExistingQuickCreate(
        Request $request,
        string $modelClass,
        array $attributes,
        RedirectResponse $redirect,
        string $labelAttribute = 'name',
        array $scopeAttributes = [],
    ): JsonResponse|RedirectResponse {
        $labelValue = trim((string) ($attributes[$labelAttribute] ?? ''));

        $existing = $this->findExistingQuickCreate(
            $modelClass,
            $labelAttribute,
            $labelValue,
            $scopeAttributes,
        );

        if ($existing !== null) {
            return $this->storeRedirectOrQuickCreateJson($request, $existing, $redirect, $labelAttribute);
        }

        /** @var Model $model */
        $model = $modelClass::query()->create($attributes);

        return $this->storeRedirectOrQuickCreateJson($request, $model, $redirect, $labelAttribute);
    }
}
