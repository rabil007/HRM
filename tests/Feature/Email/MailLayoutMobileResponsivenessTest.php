<?php

use App\Mail\FailedQueueJobMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Mail\RequirementDeadlineExtensionMail;
use App\Mail\RequirementSubmittedForApprovalMail;
use Illuminate\Support\Facades\View;

test('shared mail layout includes mobile media query rules', function () {
    $html = View::make('mail.layout', [
        'mailBranding' => [
            'brand_name' => 'OMS-HRM',
        ],
        'includeCompanyFooter' => false,
    ])->render();

    expect($html)
        ->toContain('name="viewport"')
        ->toContain('@media only screen and (max-width: 620px)')
        ->toContain('.email-section')
        ->toContain('.email-detail-row')
        ->toContain('.email-detail-label')
        ->toContain('.email-detail-value')
        ->toContain('.email-btn-link')
        ->toContain('.email-button')
        ->toContain('.email-footer-col')
        ->toContain('.email-table-scroll')
        ->toContain('email-shell');
});

test('leave request submitted mail stacks detail rows for mobile', function () {
    $html = (new LeaveRequestSubmittedMail(
        subjectLine: 'New leave request',
        organizationName: 'OMS-HRM',
        introMessage: 'Please review.',
        employeeName: 'Jane Doe',
        employeeNo: 'E-100',
        departmentName: 'Deck',
        approvalLabel: 'Current approver',
        approvalNames: ['Alex Manager'],
        approvalHelpText: null,
        leaveType: 'Annual Leave',
        leaveTypeColor: '#2563eb',
        startDate: '01 Jan 2026',
        endDate: '02 Jan 2026',
        totalDays: '2',
        reason: 'Family event',
        requestUrl: 'https://example.test/leave/1',
        includeCompanyFooter: false,
    ))->render();

    expect($html)
        ->toContain('@media only screen and (max-width: 620px)')
        ->toContain('email-section')
        ->toContain('email-detail-row')
        ->toContain('email-detail-label')
        ->toContain('email-detail-value')
        ->toContain('email-btn-link')
        ->toContain('Jane Doe')
        ->toContain('View leave request');
});

test('recruitment requirement mail stacks detail rows for mobile', function () {
    $html = (new RequirementSubmittedForApprovalMail(
        subjectLine: 'Requirement awaiting approval',
        organizationName: 'OMS-HRM',
        requirementNumber: 'REQ-1001',
        submitterName: 'Sam Requester',
        details: [
            ['label' => 'Client', 'value' => 'Acme Shipping'],
            ['label' => 'Project', 'value' => 'Vessel Alpha'],
        ],
        requirementUrl: 'https://example.test/requirements/1',
        includeCompanyFooter: false,
    ))->render();

    expect($html)
        ->toContain('@media only screen and (max-width: 620px)')
        ->toContain('email-section')
        ->toContain('email-detail-row')
        ->toContain('email-detail-label')
        ->toContain('email-detail-value')
        ->toContain('email-button')
        ->toContain('REQ-1001')
        ->toContain('Acme Shipping');
});

test('requirement deadline extension mail stacks detail rows for mobile', function () {
    $html = (new RequirementDeadlineExtensionMail(
        subjectLine: 'Deadline extension requires your approval',
        organizationName: 'OMS-HRM',
        requirementNumber: 'REQ-0041',
        heading: 'Deadline extension requires your approval',
        details: [
            ['label' => 'Current deadline', 'value' => '15 Oct 2026'],
            ['label' => 'Requested deadline', 'value' => '25 Oct 2026'],
        ],
        requirementUrl: 'https://example.test/requirements/1',
        ctaLabel: 'Review request',
        includeCompanyFooter: false,
    ))->render();

    expect($html)
        ->toContain('@media only screen and (max-width: 620px)')
        ->toContain('email-section')
        ->toContain('email-detail-row')
        ->toContain('email-detail-label')
        ->toContain('email-detail-value')
        ->toContain('email-btn-link')
        ->toContain('REQ-0041')
        ->toContain('Review request');
});

test('failed queue job mail stacks detail rows for mobile', function () {
    $html = (new FailedQueueJobMail(
        jobName: 'ExamplePreviewJob',
        queueName: 'default',
        queueConnection: 'database',
        exceptionSummary: 'Timed out',
        exceptionDetails: 'RuntimeException: Timed out',
        jobUuid: 'preview-uuid',
    ))->render();

    expect($html)
        ->toContain('@media only screen and (max-width: 620px)')
        ->toContain('email-section')
        ->toContain('email-detail-row')
        ->toContain('email-detail-label')
        ->toContain('email-detail-value')
        ->toContain('ExamplePreviewJob');
});
