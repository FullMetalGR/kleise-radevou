import { Tabs as TabsPrimitive } from 'radix-ui';
import { type ComponentProps, createContext, type ReactNode, type Ref, useContext, useState } from 'react';
import { cn } from '@/lib/utils';

/*
 * Tabs: a row of views over one place. The row is a pill on the soft tint, each tab a pill in it,
 * the chosen one filled in the ink with the page's colour for its words. The primitive gives the
 * roles, the name of the row, aria-selected on the chosen tab and the arrow keys between the tabs,
 * a tab reached by the keys being chosen. A panel kept mounted stays in the page while another is
 * shown, hidden and so out of the Tab order, and keeps its view and whatever was typed in it.
 *
 * The root is the primitive's, its props passed through. It holds the choice itself where its
 * owner gives none and shares it with the tabs and the panels below, so each tab draws its fill
 * and each kept panel hides itself without asking its owner.
 */

const Chosen = createContext('');

type RootProps = ComponentProps<typeof TabsPrimitive.Root>;

export function Tabs({ value, defaultValue, onValueChange, ...rest }: RootProps) {
    const [held, setHeld] = useState(defaultValue ?? '');
    const chosen = value ?? held;

    const choose = (next: string) => {
        setHeld(next);
        onValueChange?.(next);
    };

    return (
        <Chosen.Provider value={chosen}>
            <TabsPrimitive.Root {...rest} value={chosen} onValueChange={choose} />
        </Chosen.Provider>
    );
}

type ListProps = { label: string; className?: string; children: ReactNode };

export function TabList({ label, className, children }: ListProps) {
    return (
        <TabsPrimitive.List aria-label={label} className={cn('inline-flex gap-1 rounded-full bg-muted p-1', className)}>
            {children}
        </TabsPrimitive.List>
    );
}

type TabProps = Omit<ComponentProps<typeof TabsPrimitive.Trigger>, 'value' | 'className' | 'children'> & { value: string; className?: string; children: ReactNode };

export function Tab({ value, className, children, ...rest }: TabProps) {
    const chosen = useContext(Chosen) === value;

    return (
        <TabsPrimitive.Trigger
            {...rest}
            value={value}
            className={cn(
                'focus-ring inline-flex h-9.5 items-center justify-center rounded-full px-4 text-body-sm font-semibold whitespace-nowrap motion-safe:transition-colors',
                chosen ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground',
                className,
            )}
        >
            {children}
        </TabsPrimitive.Trigger>
    );
}

type PanelProps = { value: string; className?: string; children: ReactNode; forceMount?: boolean; ref?: Ref<HTMLDivElement> };

export function TabPanel({ value, className, children, forceMount = false, ref }: PanelProps) {
    const chosen = useContext(Chosen) === value;

    return (
        <TabsPrimitive.Content ref={ref} value={value} forceMount={forceMount ? true : undefined} hidden={!chosen} className={className}>
            {children}
        </TabsPrimitive.Content>
    );
}
