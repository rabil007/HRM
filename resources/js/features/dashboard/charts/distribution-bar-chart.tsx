import { Bar, BarChart, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export type DistributionPoint = {
    name: string;
    count: number;
};

const BAR_COLORS = [
    '#818cf8', // indigo-400
    '#60a5fa', // blue-400
    '#34d399', // emerald-400
    '#fbbf24', // amber-400
    '#a78bfa', // violet-400
    '#38bdf8', // sky-400
    '#fb923c', // orange-400
    '#4ade80', // green-400
];

export function DistributionBarChart({
    data,
    layout = 'vertical',
}: {
    data: DistributionPoint[];
    layout?: 'vertical' | 'horizontal';
}) {
    if (data.length === 0) {
        return (
            <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                No data yet
            </div>
        );
    }

    const isHorizontal = layout === 'horizontal';

    return (
        <ResponsiveContainer width="100%" height={isHorizontal ? Math.max(200, data.length * 40) : 240}>
            <BarChart
                data={data}
                layout={isHorizontal ? 'vertical' : 'horizontal'}
                margin={{ top: 4, right: 12, left: isHorizontal ? 4 : -8, bottom: 4 }}
            >
                {isHorizontal ? (
                    <>
                        <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }} />
                        <YAxis
                            type="category"
                            dataKey="name"
                            width={100}
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                        />
                    </>
                ) : (
                    <>
                        <XAxis
                            dataKey="name"
                            tickLine={false}
                            axisLine={false}
                            tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                        />
                        <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }} />
                    </>
                )}
                <Tooltip
                    cursor={{ fill: 'var(--muted)', opacity: 0.2 }}
                    contentStyle={{
                        backgroundColor: 'var(--popover)',
                        border: '1px solid var(--border)',
                        borderRadius: '0.875rem',
                        fontSize: '12px',
                        boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
                        padding: '10px 14px',
                    }}
                />
                <Bar
                    dataKey="count"
                    name="Employees"
                    radius={isHorizontal ? [0, 6, 6, 0] : [6, 6, 0, 0]}
                    maxBarSize={36}
                >
                    {data.map((_, index) => (
                        <Cell
                            key={`cell-${index}`}
                            fill={BAR_COLORS[index % BAR_COLORS.length]}
                        />
                    ))}
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
