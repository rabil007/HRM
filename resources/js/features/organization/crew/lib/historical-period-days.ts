export function historicalPeriodDaySuffix(period: {
    days?: number | null;
    allocated_service_days?: number | null;
    service_days_label?: string | null;
    calendar_span_days?: number | null;
}): string {
    if (period.service_days_label) {
        const span =
            period.calendar_span_days != null &&
            period.allocated_service_days != null &&
            period.calendar_span_days !== period.allocated_service_days
                ? ` · Movement span: ${period.calendar_span_days} ${period.calendar_span_days === 1 ? 'day' : 'days'}`
                : '';

        return ` · ${period.service_days_label}${span}`;
    }

    if (period.days != null) {
        const unit = period.days === 1 ? 'day' : 'days';

        return ` · Calendar span: ${period.days} ${unit}`;
    }

    return '';
}
