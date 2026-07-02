import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

export type AttendanceTrendPoint = {
    day: string;
    check_ins: number;
    check_outs: number;
};

export function AttendanceTrendChart({
    data,
}: {
    data: AttendanceTrendPoint[];
}) {
    if (data.length === 0) {
        return (
            <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                No attendance data yet
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={240}>
            <BarChart
                data={data}
                margin={{ top: 8, right: 8, left: -8, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    className="stroke-border/30"
                    vertical={false}
                />
                <XAxis
                    dataKey="day"
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
                <Legend
                    wrapperStyle={{ fontSize: '11px', paddingTop: '12px' }}
                    iconType="circle"
                    iconSize={8}
                />
                <Bar
                    dataKey="check_ins"
                    name="Check in"
                    fill="#34d399"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                />
                <Bar
                    dataKey="check_outs"
                    name="Check out"
                    fill="#60a5fa"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={28}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}
