<?php

namespace App\Support\EmployeeDocuments;

enum DocumentExpiryStatus: string
{
    case Valid = 'valid';
    case Expiring30 = 'expiring_30';
    case Expiring15 = 'expiring_15';
    case Expiring7 = 'expiring_7';
    case Expired = 'expired';

    // Future lifecycle states: renewed, archived
}
