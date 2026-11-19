<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use LogicException;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\Webpage;

/**
 * The admin's colour pairs and the script that measures them in the page. The colours are worked
 * out in the stylesheet by mixing the theme's primary into the mode's bases, which only a browser
 * resolves, so the measure is taken there: each token painted onto a canvas pixel and read back
 * as the sRGB the screen shows, then held against the contrast floor its use asks for. The page's
 * own ground is read back the same way, as its three channels.
 */
final class ContrastScripts
{
    /**
     * Each pair as text token, ground token and floor: 4.5 to 1 for text, 3 to 1 for the primary
     * as an outline or a large mark, for the void status's outline and for the past status's mark,
     * the neutral one the calendar draws a completed booking's edge and swatch in.
     *
     * @var list<array{string, string, float}>
     */
    public const array PAIRS = [
        ['--foreground', '--background', 4.5],
        ['--foreground', '--card', 4.5],
        ['--foreground', '--muted', 4.5],
        ['--foreground', '--tint-strong', 4.5],
        ['--muted-foreground', '--background', 4.5],
        ['--muted-foreground', '--card', 4.5],
        ['--muted-foreground', '--muted', 4.5],
        ['--ink-tertiary', '--background', 4.5],
        ['--ink-tertiary', '--card', 4.5],
        ['--primary-deep', '--background', 4.5],
        ['--primary-deep', '--card', 4.5],
        ['--primary-deep', '--muted', 4.5],
        ['--primary-deep', '--tint-strong', 4.5],
        ['--primary-foreground', '--primary', 4.5],
        ['--primary-foreground', '--primary-deep', 4.5],
        ['--background', '--foreground', 4.5],
        ['--attention-ink', '--attention-soft', 4.5],
        ['--attention-ink', '--attention-soft-strong', 4.5],
        ['--positive-ink', '--positive-soft', 4.5],
        ['--destructive', '--card', 4.5],
        ['--destructive', '--background', 4.5],
        ['--destructive', '--destructive-soft', 4.5],
        ['--destructive-foreground', '--destructive', 4.5],
        ['--spark-foreground', '--spark', 4.5],
        ['--primary', '--background', 3.0],
        ['--primary', '--card', 3.0],
        ['--admin-status-void', '--card', 3.0],
        ['--admin-status-void', '--background', 3.0],
        ['--neutral-mark', '--card', 3.0],
        ['--neutral-mark', '--background', 3.0],
    ];

    /** The page's own ground as the body paints it, to read that a theme reaches it. */
    public const string BODY_BACKGROUND = 'getComputedStyle(document.body).backgroundColor';

    /**
     * A script answering every pair under its floor in each of the seven themes, light and dark,
     * as "<theme> <mode>: <text> on <ground> = <ratio>", and every token that resolves to nothing,
     * to a value that is no colour or to a colour the canvas does not read; an empty list when all
     * hold. A token is read on a probe inside the body, where the theme is set, so it resolves as
     * the admin's own parts do. A token whose value is no colour leaves the probe the ink of what
     * holds it, which would measure as a pair that holds, so the probe sits in a holder wearing an
     * ink no token has and a probe answering that ink is reported. A ground is painted over the
     * page's own ground and the text over the ground, so a token with any transparency is
     * measured as it would show. The page's theme and mode are put back at the end.
     *
     * @param  list<array{string, string, float}>  $pairs
     */
    public static function failures(array $pairs = self::PAIRS): string
    {
        $themes = json_encode(Look::ALL_THEMES, JSON_THROW_ON_ERROR);
        $measured = json_encode($pairs, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return <<<JS
            (() => {
                const themes = {$themes};
                const pairs = {$measured};
                const body = document.body;
                const root = document.documentElement;
                const was = { theme: body.dataset.theme, dark: root.classList.contains('dark') };
                const holder = document.createElement('span');
                holder.style.position = 'fixed';
                holder.style.visibility = 'hidden';
                holder.style.color = 'rgb(3, 2, 1)';
                const probe = document.createElement('span');
                holder.appendChild(probe);
                body.appendChild(holder);
                const inherited = getComputedStyle(holder).color;
                const canvas = document.createElement('canvas');
                canvas.width = 1;
                canvas.height = 1;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                const failures = [];
                const resolve = (token) => {
                    if (getComputedStyle(body).getPropertyValue(token).trim() === '') {
                        return null;
                    }
                    probe.style.color = 'var(' + token + ')';
                    const colour = getComputedStyle(probe).color;
                    return colour === inherited ? 'no colour' : colour;
                };
                const unread = (colour) => {
                    context.fillStyle = '#010203';
                    context.fillStyle = colour;
                    return context.fillStyle === '#010203';
                };
                const paint = (colours) => {
                    context.clearRect(0, 0, 1, 1);
                    for (const colour of colours) {
                        context.fillStyle = colour;
                        context.fillRect(0, 0, 1, 1);
                    }
                    return Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
                };
                const luminance = (rgb) => {
                    const [r, g, b] = rgb.map((channel) => {
                        const c = channel / 255;
                        return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
                    });
                    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
                };
                for (const theme of themes) {
                    for (const dark of [false, true]) {
                        body.dataset.theme = theme;
                        root.classList.toggle('dark', dark);
                        const label = theme + ' ' + (dark ? 'dark' : 'light');
                        const page = resolve('--background');
                        for (const [text, ground, floor] of pairs) {
                            const colours = [[text, resolve(text)], [ground, resolve(ground)]];
                            const missing = colours.filter(([, colour]) => colour === null || unread(colour));
                            if (page === null || missing.length > 0) {
                                for (const [token, colour] of missing) {
                                    failures.push(label + ': ' + token + ' resolves to ' + (colour === null ? 'nothing' : colour));
                                }
                                continue;
                            }
                            const under = paint([page, colours[1][1]]);
                            const over = paint([page, colours[1][1], colours[0][1]]);
                            const [light, deep] = [luminance(under), luminance(over)].sort((a, b) => b - a);
                            const ratio = (light + 0.05) / (deep + 0.05);
                            if (ratio < floor) {
                                failures.push(label + ': ' + text + ' on ' + ground + ' = ' + ratio.toFixed(2));
                            }
                        }
                    }
                }
                holder.remove();
                if (was.theme === undefined) {
                    delete body.dataset.theme;
                } else {
                    body.dataset.theme = was.theme;
                }
                root.classList.toggle('dark', was.dark);
                return [...new Set(failures)];
            })()
            JS;
    }

    /**
     * The page's own ground as the red, green and blue the screen shows, each 0 to 255. A browser
     * hands back whatever the script answered, so the three are checked to be whole numbers here
     * and a test compares them as such.
     *
     * @return array{int, int, int}
     */
    public static function bodyGround(AwaitableWebpage|Webpage $page): array
    {
        $channels = $page->script(self::bodyChannels());

        if (! is_array($channels)) {
            throw new LogicException('The page answered no channels for its ground.');
        }

        $red = $channels[0] ?? null;
        $green = $channels[1] ?? null;
        $blue = $channels[2] ?? null;

        if (! is_int($red) || ! is_int($green) || ! is_int($blue)) {
            throw new LogicException('The page answered its ground as something other than a red, a green and a blue.');
        }

        return [$red, $green, $blue];
    }

    /**
     * A script answering the page's own ground as the red, green and blue the screen shows, each
     * 0 to 255: the body's colour painted onto a canvas pixel and read back, since a mixed colour
     * is not answered in sRGB.
     */
    private static function bodyChannels(): string
    {
        $ground = self::BODY_BACKGROUND;

        return <<<JS
            (() => {
                const canvas = document.createElement('canvas');
                canvas.width = 1;
                canvas.height = 1;
                const context = canvas.getContext('2d', { willReadFrequently: true });
                context.fillStyle = {$ground};
                context.fillRect(0, 0, 1, 1);
                return Array.from(context.getImageData(0, 0, 1, 1).data).slice(0, 3);
            })()
            JS;
    }
}
