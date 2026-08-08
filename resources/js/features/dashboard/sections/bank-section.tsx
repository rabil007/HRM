import { Building, CreditCard, AlertCircle, CheckCircle2 } from 'lucide-react';
import { bankAccounts } from '@/routes/organization';
import { noAccount } from '@/routes/organization/bank-accounts';
import { DashboardMetricCard } from '../components/dashboard-metric-card';
import { DashboardSection } from '../components/dashboard-section';
import type { BankAccountsDashboardSummary } from '../dashboard-types';

type BankSectionProps = {
    summary?: BankAccountsDashboardSummary;
};

export function BankSection({ summary }: BankSectionProps) {
    if (!summary) {
        return null;
    }

    return (
        <DashboardSection
            title="Bank Accounts & WPS Readiness"
            description="Employee banking setup, Ansari Exchange accounts, and payroll readiness"
            icon={Building}
            actionLabel="Bank Accounts Directory"
            actionHref={bankAccounts.url()}
        >
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <DashboardMetricCard
                    title="Total Bank Accounts"
                    value={summary.total_bank_accounts}
                    subtitle={`${summary.primary_accounts} primary accounts`}
                    icon={Building}
                    iconColor="text-blue-500"
                    href={bankAccounts.url()}
                />

                <DashboardMetricCard
                    title="Ansari Exchange Accounts"
                    value={summary.ansari_accounts}
                    subtitle="WPS-compliant card accounts"
                    icon={CreditCard}
                    iconColor="text-purple-500"
                    href={bankAccounts.url({ query: { bank: 'ansari' } })}
                />

                <DashboardMetricCard
                    title="Missing Bank Details"
                    value={summary.no_account_employees}
                    subtitle="Active employees without bank account"
                    icon={AlertCircle}
                    iconColor="text-amber-500"
                    badgeText={
                        summary.no_account_employees > 0
                            ? 'Incomplete'
                            : 'Ready'
                    }
                    badgeVariant={
                        summary.no_account_employees > 0 ? 'warning' : 'success'
                    }
                    href={noAccount.url()}
                />

                <DashboardMetricCard
                    title="Primary Accounts"
                    value={summary.primary_accounts}
                    subtitle="Configured for direct deposit"
                    icon={CheckCircle2}
                    iconColor="text-emerald-500"
                    href={bankAccounts.url({ query: { type: 'primary' } })}
                />
            </div>
        </DashboardSection>
    );
}
