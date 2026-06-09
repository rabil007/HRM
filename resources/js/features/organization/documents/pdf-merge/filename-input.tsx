import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type FilenameInputProps = {
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
};

export function FilenameInput({ value, onChange, disabled }: FilenameInputProps) {
    return (
        <div className="space-y-2">
            <Label htmlFor="merge-filename" className="text-sm text-foreground/80 dark:text-zinc-300">
                Output filename
            </Label>
            <div className="flex items-center gap-2">
                <Input
                    id="merge-filename"
                    value={value}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                    className="rounded-lg border-border bg-muted/50 text-foreground dark:border-white/10 dark:bg-zinc-950/60 dark:text-zinc-100"
                    placeholder="EMPLOYEE_NAME_DOCUMENTS_YYYYMMDD"
                />
                <span className="shrink-0 text-sm text-muted-foreground">.pdf</span>
            </div>
        </div>
    );
}
