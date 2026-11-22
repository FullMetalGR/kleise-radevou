import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mount, type Mounted, press } from '@/components/ui/harness';
import { FORCED_EDGE } from '@/components/ui/input';
import { FORCED_CHOSEN } from '@/components/ui/segmented';
import type { LanguageChoice } from '../types';
import { LanguageControl } from './LanguageControl';

/*
 * The first row's language control: each language labelled by its own name as the server hands it,
 * here the keyed names in brackets, each carrying its language as its lang; the page's language
 * pressed; a press on the other choosing it, a press on the pressed one or any press while a write
 * is in flight choosing nothing; the language being written busy under its own name. The two sit in
 * one pill on the card's fill with a line inside its edge, each a small round button, the pressed one
 * in the primary fill.
 */

const CHOICES: LanguageChoice[] = [
    { value: 'en', name: '[site.language.en]' },
    { value: 'el', name: '[site.language.el]' },
];

let mounted: Mounted;

beforeEach(() => {
    mounted = mount();
});

afterEach(() => {
    mounted.unmount();
});

function render(current: string, choosing: string | null, onChoose: (language: string) => void = () => {}): void {
    mounted.render(<LanguageControl choices={CHOICES} current={current} choosing={choosing} onChoose={onChoose} />);
}

function choice(language: string): HTMLButtonElement | null {
    return mounted.host.querySelector<HTMLButtonElement>(`.admin-language__choice[lang="${language}"]`);
}

describe('LanguageControl', () => {
    it('labels each language by its own name in its own lang, the page\'s language pressed', () => {
        render('en', null);

        expect(Array.from(mounted.host.querySelectorAll('.admin-language__choice')).map((button) => [button.getAttribute('lang'), button.textContent, button.getAttribute('aria-pressed')])).toEqual([
            ['en', '[site.language.en]', 'true'],
            ['el', '[site.language.el]', 'false'],
        ]);
    });

    it('chooses the other language on a press, and nothing on a press of the one the page is in', () => {
        const onChoose = vi.fn();
        render('el', null, onChoose);

        press(choice('el'));
        expect(onChoose).not.toHaveBeenCalled();

        press(choice('en'));
        expect(onChoose).toHaveBeenCalledExactlyOnceWith('en');
    });

    it('shows the language being written busy under its own name and chooses nothing while it is in flight', () => {
        const onChoose = vi.fn();
        render('en', 'el', onChoose);

        expect(choice('el')?.getAttribute('aria-busy')).toBe('true');
        expect(choice('el')?.textContent).toBe('[site.language.el]');
        expect(choice('en')?.getAttribute('aria-busy')).toBeNull();

        press(choice('el'));
        press(choice('en'));

        expect(onChoose).not.toHaveBeenCalled();
    });

    it('sets the two in one pill on the card\'s fill, each a small button, the pressed one alone in the primary fill', () => {
        render('el', null);

        const group = mounted.host.querySelector('.admin-language');

        expect(['rounded-full', 'bg-card', 'inset-ring'].map((name) => group?.classList.contains(name))).toEqual([true, true, true]);
        expect([choice('en'), choice('el')].map((button) => button?.classList.contains('admin-button--small'))).toEqual([true, true]);
        expect([choice('en'), choice('el')].map((button) => button?.classList.contains('bg-primary'))).toEqual([false, true]);
        expect(choice('el')?.classList.contains('text-primary-foreground')).toBe(true);
    });

    it('draws a real line round the pill where the system forces its own colours, its only edge being a ring', () => {
        render('el', null);

        expect(mounted.host.querySelector('.admin-language')?.classList.contains(FORCED_EDGE)).toBe(true);
    });

    it('paints the pressed language in the system\'s highlight where the system forces its own colours, the other in nothing of it', () => {
        render('el', null);

        expect([choice('en'), choice('el')].map((button) => FORCED_CHOSEN.split(' ').every((name) => button?.classList.contains(name)))).toEqual([false, true]);
        expect([...(choice('en')?.classList ?? [])].filter((name) => name.startsWith('forced-colors:'))).toEqual([]);
    });
});
