import { Bar, BarChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export type DistributionPoint = {
    name: string;
    count: number;
};

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
        <ResponsiveContainer width="100%" height={isHorizontal ? Math.max(200, data.length * 36) : 240}>
            <BarChart
                data={data}
                layout={isHorizontal ? 'vertical' : 'horizontal'}
                margin={{ top: 4, right: 12, left: isHorizontal ? 8 : 0, bottom: 4 }}
            >
                {isHorizontal ? (
                    <>
                        <XAxis type="number" allowDecimals={false} tickLine={false} axisLine={false} className="text-[10px] fill-muted-foreground" />
                        <YAxis
                            type="category"
                            dataKey="name"
                            width={100}
                            tickLine={false}
                            axisLine={false}
                            className="text-[10px] fill-muted-foreground"
                        />
                    </>
                ) : (
                    <>
                        <XAxis
                            dataKey="name"
                            tickLine={false}
                            axisLine={false}
                            className="text-[10px] fill-muted-foreground"
                        />
                        <YAxis allowDecimals={false} tickLine={false} axisLine={false} className="text-[10px] fill-muted-foreground" />
                    </>
                )}
                <Tooltip
                    cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    contentStyle={{
                        backgroundColor: 'var(--color-card)',
                        border: '1px solid var(--color-border)',
                        borderRadius: '0.75rem',
                        fontSize: '12px',
                    }}
                />
                <Bar
                    dataKey="count"
                    name="Employees"
                    className="fill-primary"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={40}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}
