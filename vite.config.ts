import { defineConfig, type Plugin } from 'vite';
import { ADMIN_BUILD_DIR, assertAscii, BUILD_DIR, emptyExcept, escapeKnownNonAscii, isGreek } from './vite.shared.ts';

/*
 * The one booking bundle. A tenant pastes a classic script tag, so the output is an IIFE that
 * carries React and its own stylesheet and takes nothing from the host page. The file name is
 * fixed because the script URL is stable forever; the route that serves it adds the cache
 * lifetime and the validator. publicDir is off because the default would copy public/
 * (index.php included) into the output directory, which sits inside it.
 *
 * The admin is a second build, vite.admin.config.ts, writing into a directory of its own inside
 * this one, so this build empties public/build of everything but that directory rather than
 * emptying it wholesale: neither build may touch the other's output.
 */

const BUILD_RECORD = 'embed.build.json';

/**
 * Writes, beside the bundle, what the build declared external: the module ids the entry chunk
 * still imports after bundling. The size gate refuses any, because the externals contract is
 * that the embed bundles everything it runs on, and a build that quietly externalised React
 * would pass a byte budget by leaving its biggest dependency to the host page.
 *
 * The same pass refuses non-ASCII output. Every file in this repository is ASCII, the build
 * output included, and the minifier has no switch for it, so a dependency that brought a
 * non-ASCII byte in would fail here, by name, rather than slip into a served file. The bundle's
 * Greek table is the one exception the pass expects: the minifier writes its \u escapes back as
 * Greek letters, so those are escaped again first, and any other character is refused by name
 * before the assertion runs.
 */
function buildRecord(): Plugin {
    return {
        name: 'kr:build-record',
        buildStart() {
            emptyExcept(BUILD_DIR, [ADMIN_BUILD_DIR.slice(BUILD_DIR.length + 1)]);
        },
        generateBundle(options, bundle) {
            const externals = new Set<string>();

            escapeKnownNonAscii(bundle, isGreek);
            assertAscii(bundle);

            for (const item of Object.values(bundle)) {
                if (item.type === 'chunk') {
                    for (const id of [...item.imports, ...item.dynamicImports]) {
                        externals.add(id);
                    }
                }
            }

            this.emitFile({
                type: 'asset',
                fileName: BUILD_RECORD,
                source: `${JSON.stringify({ format: options.format, externals: [...externals].sort() }, null, 4)}\n`,
            });
        },
    };
}

export default defineConfig({
    publicDir: false,
    build: {
        outDir: BUILD_DIR,
        emptyOutDir: false,
        target: 'es2020',
        sourcemap: false,
        // Off, so the plain import of the stylesheet is written as a file rather than injected
        // into the script unguarded ahead of the boot, which installs it itself, once.
        cssCodeSplit: false,
        modulePreload: { polyfill: false },
        rollupOptions: {
            input: 'resources/js/booking/embed.ts',
            output: {
                format: 'iife',
                entryFileNames: 'embed.js',
                // The one stylesheet is emitted as style.css whatever the entry is called.
                assetFileNames: (asset) => (asset.names[0] === 'style.css' ? 'embed.css' : '[name][extname]'),
            },
        },
    },
    plugins: [buildRecord()],
});
