import { Head } from '@inertiajs/react';
import { HotelCheckInCheckoutContent } from '@/features/reports/hotel-checkin-checkout/content';
import type { HotelCheckInCheckoutProps } from '@/features/reports/hotel-checkin-checkout/types';

export default function HotelCheckInCheckoutIndex(
    props: HotelCheckInCheckoutProps,
) {
    return (
        <>
            <Head title="Hotel Check-In & Check-Out" />
            <HotelCheckInCheckoutContent {...props} />
        </>
    );
}
