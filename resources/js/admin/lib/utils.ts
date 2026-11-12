import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/*
 * The class joiner every copied shadcn component imports: conditional classes through clsx, then
 * Tailwind's conflicting utilities resolved so the last one wins.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
