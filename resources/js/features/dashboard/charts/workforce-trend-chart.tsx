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

export type WorkforceTrendPoint = {
    month: string;
    headcount: number;
    new_hires: number;
    documents: number;
};

export function WorkforceTrendChart({ data }: { data: WorkforceTrendPoint[] }) {
    if (data.length === 0) {
        return (
            <div className="flex h-[280px] items-center justify-center text-sm text-muted-foreground">
                No trend data yet
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={280}>
            <AreaChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                <defs>
                    <linearGradient id="headcountGradient" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--color-primary)" stopOpacity={0.35} />
                        <stop offset="95%" stopColor="var(--color-primary)" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" className="stroke-border/40" vertical={false} />
                <XAxis
                    dataKey="month"
                    tickLine={false}
                    axisLine={false}
                    className="text-[10px] fill-muted-foreground"
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    className="text-[10px] fill-muted-foreground"
                    allowDecimals={false}
                />
                <Tooltip
                    contentStyle={{
                        backgroundColor: 'var(--color-card)',
                        border: '1px solid var(--color-border)',
                        borderRadius: '0.75rem',
                        fontSize: '12px',
                    }}
                />
                <Legend
                    wrapperStyle={{ fontSize: '11px', paddingTop: '8px' }}
                    iconType="circle"
                    iconSize={8}
                />
                <Area
                    type="monotone"
                    dataKey="headcount"
                    name="Headcount"
                    stroke="var(--color-primary)"
                    strokeWidth={2}
                    fill="url(#headcountGradient)"
                />
                <Area
                    type="monotone"
                    dataKey="new_hires"
                    name="New hires"
                    stroke="#34d399"
                    strokeWidth={2}
                    fill="transparent"
                />
                <Area
                    type="monotone"
                    dataKey="documents"
                    name="Documents"
                    stroke="#60a5fa"
                    strokeWidth={2}
                    strokeDasharray="4 4"
                    fill="transparent"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
