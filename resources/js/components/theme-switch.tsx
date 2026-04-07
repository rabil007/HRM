import { Check, Moon, Sun } from 'lucide-react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';

export function ThemeSwitch() {
    const { appearance, updateAppearance } = useAppearance();

    useEffect(() => {
        const themeColor = appearance === 'dark' ? '#020817' : '#fff';
        const metaThemeColor = document.querySelector(
            "meta[name='theme-color']",
        );

        if (metaThemeColor) {
            metaThemeColor.setAttribute('content', themeColor);
        }
    }, [appearance]);

    return (
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="scale-95 rounded-full"
                >
                    <Sun className="size-[1.2rem] scale-100 rotate-0 transition-all dark:scale-0 dark:-rotate-90" />
                    <Moon className="absolute size-[1.2rem] scale-0 rotate-90 transition-all dark:scale-100 dark:rotate-0" />
                    <span className="sr-only">Toggle theme</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => updateAppearance('light')}>
                    Light{' '}
                    <Check
                        size={14}
                        className={cn(
                            'ms-auto',
                            appearance !== 'light' && 'hidden',
                        )}
                    />
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => updateAppearance('dark')}>
                    Dark
                    <Check
                        size={14}
                        className={cn(
                            'ms-auto',
                            appearance !== 'dark' && 'hidden',
                        )}
                    />
                </DropdownMenuItem>
                <DropdownMenuItem onClick={() => updateAppearance('system')}>
                    System
                    <Check
                        size={14}
                        className={cn(
                            'ms-auto',
                            appearance !== 'system' && 'hidden',
                        )}
                    />
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
