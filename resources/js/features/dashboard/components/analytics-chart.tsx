import { Area, AreaChart, ResponsiveContainer, XAxis, YAxis } from 'recharts';

const data = [
    {
        name: 'Jan',
        headcount: 1100,
        hiring: 45,
    },
    {
        name: 'Feb',
        headcount: 1150,
        hiring: 52,
    },
    {
        name: 'Mar',
        headcount: 1200,
        hiring: 48,
    },
    {
        name: 'Apr',
        headcount: 1220,
        hiring: 61,
    },
    {
        name: 'May',
        headcount: 1250,
        hiring: 55,
    },
    {
        name: 'Jun',
        headcount: 1284,
        hiring: 42,
    },
];

export function AnalyticsChart() {
    return (
        <ResponsiveContainer width="100%" height={350}>
            <AreaChart data={data}>
                <defs>
                    <linearGradient id="colorHeadcount" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="var(--color-primary)" stopOpacity={0.3} />
                        <stop offset="95%" stopColor="var(--color-primary)" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <XAxis
                    dataKey="name"
                    stroke="currentColor"
                    className="text-muted-foreground"
                    fontSize={10}
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={(value) => value.toUpperCase()}
                />
                <YAxis
                    stroke="currentColor"
                    className="text-muted-foreground"
                    fontSize={10}
                    tickLine={false}
                    axisLine={false}
                    tickFormatter={(value) => `${value}`}
                />
                <Area
                    type="monotone"
                    dataKey="headcount"
                    stroke="var(--color-primary)"
                    strokeWidth={2}
                    fillOpacity={1}
                    fill="url(#colorHeadcount)"
                />
                <Area
                    type="monotone"
                    dataKey="hiring"
                    stroke="currentColor"
                    className="text-muted-foreground/30"
                    strokeWidth={1}
                    strokeDasharray="4 4"
                    fill="transparent"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
