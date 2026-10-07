<?php

namespace App\Support\MasterData;

use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MasterDataQuickCreate
{
    public const CLIENT_INACTIVE_MESSAGE = 'A client with this name already exists but is inactive. Activate the existing client before using it.';

    public const CLIENT_DELETED_MESSAGE = 'A deleted client with this name already exists. Restore or rename the existing record before creating another.';

    public const PROJECT_INACTIVE_MESSAGE = 'A project with this title already exists but is inactive. Activate the existing project before using it.';

    public const PROJECT_DELETED_MESSAGE = 'A deleted project with this title already exists. Restore or rename the existing record before creating another.';

    public const PROJECT_NOT_LINKED_MESSAGE = 'A project with this title already exists but is not linked to the selected client. Link it in master data or choose another project.';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function storeClient(
        Request $request,
        array $attributes,
        callable $jsonResponse,
        callable $redirectResponse,
    ): JsonResponse|RedirectResponse {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'The name field is required.',
            ]);
        }

        $match = self::findClientByNormalizedName($name);

        if ($match instanceof Client) {
            return self::respondWithClient($request, $match, $jsonResponse, $redirectResponse);
        }

        try {
            /** @var Client $created */
            $created = Client::query()->create($attributes);
        } catch (UniqueConstraintViolationException $exception) {
            $match = self::findClientByNormalizedName($name);

            if ($match instanceof Client) {
                return self::respondWithClient($request, $match, $jsonResponse, $redirectResponse);
            }

            throw $exception;
        }

        return $request->wantsJson()
            ? $jsonResponse($created)
            : $redirectResponse($created);
    }

    public static function findClientByNormalizedName(string $name): ?Client
    {
        $normalized = mb_strtolower(trim($name));

        if ($normalized === '') {
            return null;
        }

        return Client::withTrashed()
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->first();
    }

    public static function findProjectByNormalizedTitle(string $title): ?Project
    {
        $normalized = mb_strtolower(trim($title));

        if ($normalized === '') {
            return null;
        }

        return Project::withTrashed()
            ->whereRaw('LOWER(title) = ?', [$normalized])
            ->first();
    }

    /**
     * @return never
     */
    public static function failClientMatch(Client $client): void
    {
        if ($client->trashed()) {
            throw ValidationException::withMessages([
                'name' => self::CLIENT_DELETED_MESSAGE,
            ]);
        }

        if (! $client->is_active) {
            throw ValidationException::withMessages([
                'name' => self::CLIENT_INACTIVE_MESSAGE,
            ]);
        }
    }

    /**
     * @return never
     */
    public static function failProjectMatch(Project $project): void
    {
        if ($project->trashed()) {
            throw ValidationException::withMessages([
                'title' => self::PROJECT_DELETED_MESSAGE,
            ]);
        }

        if (! $project->is_active) {
            throw ValidationException::withMessages([
                'title' => self::PROJECT_INACTIVE_MESSAGE,
            ]);
        }
    }

    /**
     * @param  callable(Client): JsonResponse  $jsonResponse
     * @param  callable(Client): RedirectResponse  $redirectResponse
     */
    private static function respondWithClient(
        Request $request,
        Client $client,
        callable $jsonResponse,
        callable $redirectResponse,
    ): JsonResponse|RedirectResponse {
        self::failClientMatch($client);

        return $request->wantsJson()
            ? $jsonResponse($client)
            : $redirectResponse($client);
    }

    public static function isUniqueConstraintViolation(\Throwable $exception): bool
    {
        return $exception instanceof UniqueConstraintViolationException;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
}
