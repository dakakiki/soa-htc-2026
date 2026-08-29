import { defineConfig } from 'vitest/config';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';

/**
 * The front end's own test configuration, deliberately apart from
 * `vite.config.ts`.
 *
 * 🪤 `laravel-vite-plugin` is not here on purpose: it writes the hot file and
 * expects the Blade entry points, neither of which exists in a test run. Tailwind
 * is not here because nothing under test asks what a class computes to. What is
 * left is what a component actually needs — the Vue compiler and the `@` alias.
 *
 * The tests live under `tests/js`, beside `tests/Unit` and `tests/Feature`, so
 * the whole suite is in one place. PHPUnit names those two directories
 * explicitly (`phpunit.xml`), so it never tries to read these.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        /*
         * 🪤 happy-dom rather than jsdom, and not by preference: jsdom 30 pulls in
         * an undici that needs a newer Node than this project runs on, and fails
         * on import before a single test is collected.
         */
        environment: 'happy-dom',
        include: ['tests/js/**/*.spec.ts'],
        setupFiles: ['tests/js/setup.ts'],
        // Imported rather than global, so a reader can see where `expect` came from.
        globals: false,
        restoreMocks: true,
        /*
         * 🪤 Both, and they are not the same thing. `restoreMocks` puts original
         * implementations back; only `clearMocks` forgets the calls. Without it a
         * test that reads `mock.calls[0]` reads the previous test's call and
         * passes or fails on somebody else's work.
         */
        clearMocks: true,
    },
});
