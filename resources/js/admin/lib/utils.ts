import { type ClassValue, clsx } from 'clsx';
import { extendTailwindMerge } from 'tailwind-merge';

/*
 * The class merger, taught the admin's own scales (app.css). It knows a stock size or radius by
 * its shape, so a named one such as text-page-title would be read as a colour and dropped beside
 * text-foreground, or kept beside another size; each name is listed here under its scale, without
 * the scale's prefix, so it conflicts with its own kind alone. The two faces need no line: any
 * name after font- that is no weight is already read as a face.
 */
const twMerge = extendTailwindMerge({
    extend: {
        theme: {
            text: ['page-title', 'page-title-compact', 'detail-title', 'dialog-title', 'section-title', 'card-title', 'tile-title', 'body', 'body-sm', 'label', 'small', 'eyebrow'],
            radius: ['tile', 'tile-compact', 'shell', 'inner', 'menu', 'notice', 'field', 'event'],
            shadow: ['card', 'glow', 'glow-hover', 'float', 'dock', 'modal', 'drawer'],
            ease: ['soft'],
            container: ['shell-narrow'],
        },
    },
});

/*
 * The class joiner every copied shadcn component imports: conditional classes through clsx, then
 * Tailwind's conflicting utilities resolved so the last one wins.
 */
export function cn(...inputs: ClassValue[]): string {
    return twMerge(clsx(inputs));
}
