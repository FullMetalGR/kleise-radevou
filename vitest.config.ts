import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

/*
 * The component tests over resources/js. A DOM environment, because the bundle's whole job is
 * mounting into one; explicit imports rather than globals. The stylesheet audit reads the built
 * stylesheet, so npm run build precedes npm run test:js, the order the gates run in. The
 * build's own escape, vite.shared.ts, is proven beside it at the root. The @ alias is the admin
 * folder's, as vite.admin.config.ts spells it; the booking app imports nothing through it. The
 * workers are capped at four because the default follows the core count and exhausts the
 * development machine's memory.
 */
export default defineConfig({
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js/admin', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        maxWorkers: 4,
        include: ['resources/js/**/*.test.{ts,tsx}', 'vite.shared.test.ts'],
        setupFiles: ['resources/js/vitest.setup.ts'],
    },
});
