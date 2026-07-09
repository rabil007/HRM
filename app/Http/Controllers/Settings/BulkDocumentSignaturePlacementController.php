<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBulkDocumentSignaturePlacementRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentSignaturePlacementService;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

class BulkDocumentSignaturePlacementController extends Controller
{
    public function __construct(
        private BulkDocumentSignaturePlacementService $placements,
    ) {}

    public function preview(string $documentType): SymfonyResponse
    {
        $this->authorizeView();
        $this->assertSupportedDocumentType($documentType);

        $companyId = (int) request()->attributes->get('current_company_id');
        $employee = $this->resolvePreviewEmployee($companyId);
        $showGuides = request()->boolean('guides', true);

        // #region agent log
        $nodeBinary = ResolvesBrowsershotBinaries::nodeBinary();
        $npmBinary = ResolvesBrowsershotBinaries::npmBinary();
        $chromePath = ResolvesBrowsershotBinaries::chromePath();
        file_put_contents(
            base_path('.cursor/debug-aa4780.log'),
            json_encode([
                'sessionId' => 'aa4780',
                'location' => 'BulkDocumentSignaturePlacementController.php:preview:pre-render',
                'message' => 'E-sign preview render starting',
                'data' => [
                    'documentType' => $documentType,
                    'companyId' => $companyId,
                    'employeeId' => $employee->id,
                    'showGuides' => $showGuides,
                    'nodeBinary' => $nodeBinary,
                    'npmBinary' => $npmBinary,
                    'chromePath' => $chromePath,
                    'chromeExecutable' => is_string($chromePath) && is_executable($chromePath),
                    'path' => getenv('PATH') ?: null,
                    'home' => getenv('HOME') ?: null,
                ],
                'timestamp' => (int) (microtime(true) * 1000),
                'hypothesisId' => 'B',
                'runId' => 'pre-fix',
            ]).PHP_EOL,
            FILE_APPEND,
        );
        // #endregion

        try {
            $pdf = app(SalaryDeclarationPdfRenderer::class)->render(
                $employee,
                $companyId,
                null,
                $showGuides,
            );
        } catch (ProcessFailedException|Throwable $exception) {
            // #region agent log
            file_put_contents(
                base_path('.cursor/debug-aa4780.log'),
                json_encode([
                    'sessionId' => 'aa4780',
                    'location' => 'BulkDocumentSignaturePlacementController.php:preview',
                    'message' => 'E-sign preview PDF generation failed',
                    'data' => [
                        'error' => $exception->getMessage(),
                        'exceptionClass' => $exception::class,
                        'documentType' => $documentType,
                        'companyId' => $companyId,
                        'employeeId' => $employee->id,
                        'nodeBinary' => $nodeBinary,
                        'npmBinary' => $npmBinary,
                        'chromePath' => $chromePath,
                        'path' => getenv('PATH') ?: null,
                        'home' => getenv('HOME') ?: null,
                    ],
                    'timestamp' => (int) (microtime(true) * 1000),
                    'hypothesisId' => 'A',
                    'runId' => 'pre-fix',
                ]).PHP_EOL,
                FILE_APPEND,
            );
            // #endregion

            abort(
                503,
                'PDF preview is unavailable on this server. SSH in and run: php artisan browsershot:doctor',
            );
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="esign-preview.pdf"',
        ]);
    }

    public function show(string $documentType): JsonResponse
    {
        $this->authorizeView();
        $this->assertSupportedDocumentType($documentType);

        $placement = $this->placements->resolve($documentType);
        $defaults = $this->placements->defaults($documentType);

        return response()->json([
            'placement' => $placement,
            'is_custom' => $placement !== null
                && $defaults !== null
                && json_encode($placement) !== json_encode($defaults),
        ]);
    }

    public function update(
        UpdateBulkDocumentSignaturePlacementRequest $request,
        string $documentType,
    ): JsonResponse {
        $this->assertSupportedDocumentType($documentType);

        $validated = $request->validated();

        $config = $this->placements->fromEditorRects(
            signature: $validated['signature'],
            date: $validated['date'],
            signatureAr: $validated['signature_ar'],
            dateAr: $validated['date_ar'],
            canvasWidth: (float) $validated['canvas_width'],
            canvasHeight: (float) $validated['canvas_height'],
            page: (int) $validated['page'],
        );

        $this->placements->save($documentType, $config);

        return response()->json([
            'placement' => $config,
            'message' => 'Signature placement saved.',
        ]);
    }

    public function destroy(string $documentType): JsonResponse
    {
        abort_unless(request()->user()?->can('settings.application.update'), 403);

        $this->assertSupportedDocumentType($documentType);

        $this->placements->resetToDefaults($documentType);

        return response()->json([
            'placement' => $this->placements->resolve($documentType),
            'message' => 'Signature placement reset to defaults.',
        ]);
    }

    private function authorizeView(): void
    {
        abort_unless(request()->user()?->can('settings.application.view'), 403);
    }

    private function assertSupportedDocumentType(string $documentType): void
    {
        abort_unless(
            BulkDocumentTypeRegistry::supportsEsignature($documentType)
                && $this->placements->supportsDocumentType($documentType),
            404,
        );
    }

    private function resolvePreviewEmployee(int $companyId): Employee
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->first();

        if ($employee instanceof Employee) {
            return $employee;
        }

        $company = Company::query()->findOrFail($companyId);

        return Employee::factory()->forCompany($company)->create([
            'name' => 'Jane Smith',
            'status' => 'active',
            'emirates_id' => '784-1990-0000000-1',
        ]);
    }
}
