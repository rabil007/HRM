<?php

namespace App\Http\Controllers\Settings\MasterData;

use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\MasterData\ImportCoursesRequest;
use App\Http\Requests\Settings\MasterData\StoreCourseRequest;
use App\Http\Requests\Settings\MasterData\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CourseController extends Controller
{
    use ReturnsQuickCreateJson;

    public function index(): InertiaResponse
    {
        $courses = Course::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);

        return Inertia::render('settings/master-data/courses', [
            'courses' => $courses,
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $data['is_active'] ?? true;

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Course::class,
            $data,
            redirect()->route('settings.master-data.courses.index'),
        );
    }

    public function update(UpdateCourseRequest $request, Course $course): RedirectResponse
    {
        $course->update($request->validated());

        return redirect()->route('settings.master-data.courses.index');
    }

    public function destroy(Course $course): RedirectResponse
    {
        $course->delete();

        return redirect()->route('settings.master-data.courses.index');
    }

    public function importTemplate(): Response
    {
        $csv = "name,is_active\nSTCW Basic Safety,yes\nAdvanced Fire Fighting,yes\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="courses-import-template.csv"',
        ]);
    }

    public function import(ImportCoursesRequest $request): RedirectResponse
    {
        $uploaded = $request->file('file');
        $path = $uploaded->getRealPath() ?: $uploaded->path();
        $handle = fopen((string) $path, 'r');

        if ($handle === false) {
            return redirect()
                ->route('settings.master-data.courses.index')
                ->withErrors(['file' => 'Could not read the uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (! is_array($header) || count($header) === 0) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.courses.index')
                ->withErrors(['file' => 'The CSV file is empty.']);
        }

        $map = [];
        foreach ($header as $index => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (in_array($key, ['name', 'title', 'course', 'course name', 'course title'], true)) {
                $map['name'] = (int) $index;
            }
            if (in_array($key, ['active', 'is_active', 'status', 'enabled'], true)) {
                $map['active'] = (int) $index;
            }
        }

        if (! isset($map['name'])) {
            fclose($handle);

            return redirect()
                ->route('settings.master-data.courses.index')
                ->withErrors(['file' => 'The CSV must include a name or title column.']);
        }

        $imported = 0;
        $emptyNames = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row[$map['name']] ?? ''));
            if ($name === '') {
                $emptyNames++;

                continue;
            }

            $active = true;
            if (isset($map['active'])) {
                $v = mb_strtolower(trim((string) ($row[$map['active']] ?? '')));
                $active = $v === '' || in_array($v, ['1', 'yes', 'true', 'y', 'active'], true);
            }

            Course::query()->updateOrCreate(
                ['name' => $name],
                ['is_active' => $active],
            );
            $imported++;

            if ($imported > 2000) {
                break;
            }
        }

        fclose($handle);

        if ($imported === 0) {
            return redirect()
                ->route('settings.master-data.courses.index')
                ->withErrors([
                    'file' => $emptyNames > 0
                        ? "No rows were imported. {$emptyNames} row(s) had an empty name."
                        : 'No rows were imported. Ensure each row has a name or title.',
                ]);
        }

        return redirect()
            ->route('settings.master-data.courses.index')
            ->with('success', "Imported {$imported} course row(s).");
    }
}
