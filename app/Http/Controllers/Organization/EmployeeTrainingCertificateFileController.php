<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Support\EmployeeTrainings\TrainingAccess;
use App\Support\EmployeeTrainings\TrainingCertificateDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeTrainingCertificateFileController extends Controller
{
    public function show(
        Request $request,
        Employee $employee,
        EmployeeTraining $training,
        TrainingCertificateDownloadService $downloads,
    ): Response {
        TrainingAccess::assertCanAccessCertificate($request->user());

        return $downloads->downloadCurrent(
            $employee,
            $training,
            (int) $request->attributes->get('current_company_id'),
            $request->boolean('inline'),
        );
    }

    public function version(
        Request $request,
        Employee $employee,
        EmployeeTraining $training,
        EmployeeTrainingVersion $version,
        TrainingCertificateDownloadService $downloads,
    ): Response {
        TrainingAccess::assertCanAccessCertificate($request->user());

        return $downloads->downloadVersion(
            $employee,
            $training,
            $version,
            (int) $request->attributes->get('current_company_id'),
            $request->boolean('inline'),
        );
    }
}
