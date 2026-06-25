<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LeaveRequestDecidedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $organizationName,
        public ?string $introMessage,
        public string $employeeName,
        public string $employeeNo,
        public string $departmentName,
        public string $managerName,
        public string $leaveType,
        public ?string $leaveTypeColor,
        public string $startDate,
        public string $endDate,
        public string $totalDays,
        public string $reason,
        public string $requestUrl,
        public string $status,
        public ?string $rejectionReason = null,
        public bool $includeCompanyFooter = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.leave-request-decided',
        );
    }
}
