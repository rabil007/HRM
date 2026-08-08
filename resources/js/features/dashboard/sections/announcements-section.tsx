import { Megaphone, CheckCircle2, Clock, AlertTriangle } from 'lucide-react';
import { index as announcementsIndex } from '@/routes/organization/announcements';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { AnnouncementsDashboardSummary } from '../dashboard-types';

type AnnouncementsSectionProps = {
    summary?: AnnouncementsDashboardSummary;
};

export function AnnouncementsSection({ summary }: AnnouncementsSectionProps) {
    if (!summary) {
        return null;
    }

    return (
        <DashboardSection
            title="Company Announcements"
            description="Broadcast communications, scheduled releases, and delivery health"
            icon={Megaphone}
            actionLabel="Announcements Portal"
            actionHref={announcementsIndex.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Published Announcements"
                    value={summary.published}
                    subtitle={`${summary.total} total announcements`}
                    icon={CheckCircle2}
                    iconColor="text-emerald-500"
                    href={announcementsIndex.url({
                        query: { status: 'published' },
                    })}
                />

                <DashboardMetricCard
                    title="Scheduled Releases"
                    value={summary.scheduled}
                    subtitle="Upcoming automated broadcasts"
                    icon={Clock}
                    iconColor="text-blue-500"
                    href={announcementsIndex.url({
                        query: { status: 'scheduled' },
                    })}
                />

                <DashboardMetricCard
                    title="Failed Deliveries"
                    value={summary.failed_deliveries}
                    subtitle="Broadcast errors requiring retry"
                    icon={AlertTriangle}
                    iconColor="text-rose-500"
                    badgeText={
                        summary.failed_deliveries > 0
                            ? 'Delivery Error'
                            : 'All Sent'
                    }
                    badgeVariant={
                        summary.failed_deliveries > 0 ? 'danger' : 'success'
                    }
                    href={announcementsIndex.url({
                        query: { tab: 'deliveries' },
                    })}
                />

                <DashboardMetricCard
                    title="Total Broadcasts"
                    value={summary.total}
                    subtitle="Management announcement records"
                    icon={Megaphone}
                    iconColor="text-amber-500"
                    href={announcementsIndex.url()}
                />
            </div>
        </DashboardSection>
    );
}
