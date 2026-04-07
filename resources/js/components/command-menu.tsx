import { router } from '@inertiajs/react';
import { ArrowRight, ChevronRight, Laptop, Moon, Sun } from 'lucide-react';
import React from 'react';
import { sidebarData } from '@/components/layout/data/sidebar-data';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useSearch } from '@/context/search-provider';
import { useAppearance } from '@/hooks/use-appearance';

export function CommandMenu() {
    const { updateAppearance } = useAppearance();
    const { open, setOpen } = useSearch();

    const runCommand = React.useCallback(
        (command: () => unknown) => {
            setOpen(false);
            command();
        },
        [setOpen],
    );

    return (
        <CommandDialog modal open={open} onOpenChange={setOpen}>
            <CommandInput placeholder="Type a command or search..." />
            <CommandList>
                <ScrollArea type="hover" className="h-72 pe-1">
                    <CommandEmpty>No results found.</CommandEmpty>
                    {sidebarData.navGroups.map((group) => (
                        <CommandGroup key={group.title} heading={group.title}>
                            {group.items.map((navItem, i) => {
                                if (navItem.url) {
                                    return (
                                        <CommandItem
                                            key={`${navItem.url}-${i}`}
                                            value={navItem.title}
                                            onSelect={() => {
                                                runCommand(() => {
                                                    router.visit(
                                                        navItem.url as string,
                                                    );
                                                });
                                            }}
                                        >
                                            <div className="flex size-4 items-center justify-center">
                                                <ArrowRight className="size-2 text-muted-foreground/80" />
                                            </div>
                                            {navItem.title}
                                        </CommandItem>
                                    );
                                }

                                return navItem.items?.map((subItem, j) => (
                                    <CommandItem
                                        key={`${navItem.title}-${subItem.url}-${j}`}
                                        value={`${navItem.title}-${subItem.url}`}
                                        onSelect={() => {
                                            runCommand(() => {
                                                router.visit(subItem.url);
                                            });
                                        }}
                                    >
                                        <div className="flex size-4 items-center justify-center">
                                            <ArrowRight className="size-2 text-muted-foreground/80" />
                                        </div>
                                        {navItem.title} <ChevronRight />{' '}
                                        {subItem.title}
                                    </CommandItem>
                                ));
                            })}
                        </CommandGroup>
                    ))}
                    <CommandSeparator />
                    <CommandGroup heading="Theme">
                        <CommandItem
                            onSelect={() =>
                                runCommand(() => updateAppearance('light'))
                            }
                        >
                            <Sun /> <span>Light</span>
                        </CommandItem>
                        <CommandItem
                            onSelect={() =>
                                runCommand(() => updateAppearance('dark'))
                            }
                        >
                            <Moon className="scale-90" />
                            <span>Dark</span>
                        </CommandItem>
                        <CommandItem
                            onSelect={() =>
                                runCommand(() => updateAppearance('system'))
                            }
                        >
                            <Laptop />
                            <span>System</span>
                        </CommandItem>
                    </CommandGroup>
                </ScrollArea>
            </CommandList>
        </CommandDialog>
    );
}
