import { fileURLToPath } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, type Plugin } from 'vite';
import { ADMIN_BUILD_DIR, assertAscii, escapeKnownNonAscii } from './vite.shared.ts';

/*
 * The admin bundle: a second Vite build beside the embed's, not a second entry of the same one.
 * It writes an ES module app with hashed file names and a manifest into public/build/admin, which
 * the admin's root view reads through the framework's Vite helper, and it empties that directory
 * and nothing else. The embed's build leaves this directory alone in turn, and the size gate
 * measures the embed alone: the admin has no byte budget.
 *
 * The ASCII assertion the embed's build makes covers this output too. Before it runs, the three
 * characters the calendar grid's library spells as \uXXXX in its source, which the minifier
 * writes out literally, are rewritten back to escapes in every chunk; any other non-ASCII
 * character in a chunk, and any at all in an asset, fails the build by name, so a dependency
 * bringing one in is looked at rather than escaped unread.
 *
 * The @ alias is the admin folder, which is what the copied shadcn components import through.
 * The booking app imports nothing through it.
 *
 * The marketing site's stylesheet is a second entry of this build: the site's pages are Blade
 * rendered by the server, so there is no site bundle and no third build, and the layout links the
 * stylesheet through the manifest as the admin's root view links its app. The consent banner's
 * script is a third entry, the one script the site runs: the central host's two root views link
 * it beside the banner while the analytics tag's id is set.
 */
function asciiOutput(): Plugin {
    return {
        name: 'kr:ascii-output',
        generateBundle(_options, bundle) {
            escapeKnownNonAscii(bundle);
            assertAscii(bundle);
        },
    };
}

export default defineConfig({
    publicDir: false,
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js/admin', import.meta.url)) },
    },
    build: {
        outDir: ADMIN_BUILD_DIR,
        emptyOutDir: true,
        target: 'es2020',
        sourcemap: false,
        manifest: 'manifest.json',
        rollupOptions: {
            input: ['resources/js/admin/app.tsx', 'resources/js/site/site.css', 'resources/js/site/consent.ts'],
            // Radix marks its modules "use client" for server-component frameworks. There are no
            // server components here, so the bundler dropping the directive changes nothing.
            onwarn(warning, warn) {
                if (warning.code !== 'MODULE_LEVEL_DIRECTIVE') {
                    warn(warning);
                }
            },
        },
    },
    plugins: [tailwindcss(), asciiOutput()],
});
