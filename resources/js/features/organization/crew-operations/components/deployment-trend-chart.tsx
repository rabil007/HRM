import {
    Area,
    AreaChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type { CrewOperationsDeploymentTrendPoint } from '@/features/organization/crew-operations/types';

export function DeploymentTrendChart({ data }: { data: CrewOperationsDeploymentTrendPoint[] }) {
    if (data.length === 0) {
        return (
            <div className="flex h-[280px] items-center justify-center text-sm text-muted-foreground">
                No trend data yet
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={280}>
            <AreaChart data={data} margin={{ top: 8, right: 8, left: -8, bottom: 0 }}>
                <defs>
                    <linearGradient id="joinsGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--primary)" stopOpacity={0.3} />
                        <stop offset="95%" stopColor="var(--primary)" stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="disembarksGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#f97316" stopOpacity={0.2} />
                        <stop offset="95%" stopColor="#f97316" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border/30" vertical={false} />
                <XAxis
                    dataKey="month"
                    tickLine={false}
                    axisLine={false}
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                    allowDecimals={false}
                />
                <Tooltip
                    contentStyle={{
                        backgroundColor: 'var(--popover)',
                        border: '1px solid var(--border)',
                        borderRadius: '12px',
                        fontSize: '12px',
                    }}
                />
                <Legend wrapperStyle={{ fontSize: '12px' }} />
                <Area
                    type="monotone"
                    dataKey="joins"
                    name="Joins"
                    stroke="var(--primary)"
                    fill="url(#joinsGradient)"
                    strokeWidth={2}
                />
                <Area
                    type="monotone"
                    dataKey="disembarks"
                    name="Disembarks"
                    stroke="#f97316"
                    fill="url(#disembarksGradient)"
                    strokeWidth={2}
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
