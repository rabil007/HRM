<?php

namespace App\Support\Reports;

use App\Models\User;

final class HotelCheckInCheckoutPagePermissions
{
    /**
     * @return array{export: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'export' => $user?->can('reports.hotel_checkin_checkout.export') ?? false,
        ];
    }
}
