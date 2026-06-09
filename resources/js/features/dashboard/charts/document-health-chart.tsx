import { Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

export type DocumentHealthSlice = {
    name: string;
    value: number;
    key: string;
};

const SLICE_COLORS: Record<string, string> = {
    compliant: '#34d399',
    expiring_30: '#fbbf24',
    expiring_7: '#f97316',
    expired: '#f87171',
};

export function DocumentHealthChart({ data }: { data: DocumentHealthSlice[] }) {
    if (data.length === 0) {
        return (
            <div className="flex h-[240px] flex-col items-center justify-center gap-2 text-center">
                <p className="text-sm font-medium text-muted-foreground">No documents tracked</p>
                <p className="text-xs text-muted-foreground/80">Upload employee documents to see health breakdown</p>
            </div>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={240}>
            <PieChart>
                <Pie
                    data={data}
                    cx="50%"
                    cy="48%"
                    innerRadius={58}
                    outerRadius={88}
                    paddingAngle={3}
                    dataKey="value"
                    nameKey="name"
                    strokeWidth={0}
                >
                    {data.map((entry) => (
                        <Cell
                            key={entry.key}
                            fill={SLICE_COLORS[entry.key] ?? 'var(--primary)'}
                        />
                    ))}
                </Pie>
                <Tooltip
                    contentStyle={{
                        backgroundColor: 'var(--popover)',
                        border: '1px solid var(--border)',
                        borderRadius: '0.875rem',
                        fontSize: '12px',
                        boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
                        padding: '10px 14px',
                    }}
                />
                <Legend
                    wrapperStyle={{ fontSize: '11px' }}
                    iconType="circle"
                    iconSize={7}
                />
            </PieChart>
        </ResponsiveContainer>
    );
}
