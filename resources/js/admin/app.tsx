import './app.css';
import { createInertiaApp } from '@inertiajs/react';
import { type ComponentType, createElement, type ReactNode } from 'react';
import { createRoot } from 'react-dom/client';
import { pageLayout } from './layouts';
import { ShellProvider, shellOf } from './lib/strings';
import { applyTheme, readTheme } from './theme';
import { brandNameOf, pageTitle } from './title';
import { ToastProvider } from './toast/toasts';

/*
 * The admin's entry: Inertia over the session, the pages under ./pages. A page under auth/ or
 * signup/ renders alone and every other inside the shell; signup's are served on the central
 * host. A page under console/ is the platform console's, served on the console's host: its door
 * renders alone and every other inside the console's shell. The theme is applied before the first
 * render so a dark machine does not flash light. The title names the platform by the public name
 * the first page's brand prop carries, read before the first render, never a name compiled in.
 *
 * Every page renders inside the shell's words, read off its own props on every host: the admin's
 * shell section in the page's language, the console's in English, the central host's kit groups
 * in the request's. The toaster sits inside them, mounted here once for every host, so its words
 * follow the page while the toasts it holds outlive a change of frame; the page itself renders in
 * its frame as Inertia renders a layout.
 */

type PageModule = { default: ComponentType & { layout?: (page: ReactNode) => ReactNode } };

const pages = import.meta.glob<PageModule>(['./pages/**/*.tsx', '!./pages/**/*.test.tsx'], { eager: true });

applyTheme(
    readTheme(() => window.localStorage),
    document.documentElement,
);

/** The public name every title ends with, from the first page's brand prop; set before the first render. */
let brandName = '';

void createInertiaApp({
    title: (title) => pageTitle(title, brandName),
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (page === undefined) {
            throw new Error(`No admin page is called ${name}.`);
        }

        page.default.layout ??= pageLayout(name);

        return page;
    },
    setup({ el, App, props }) {
        brandName = brandNameOf(props.initialPage.props);

        createRoot(el).render(
            <App {...props}>
                {({ Component, key, props: pageProps }) => {
                    const page = Component as PageModule['default'];
                    const child = createElement(page, { key, ...pageProps });
                    const { language, strings } = shellOf(pageProps);

                    return (
                        <ShellProvider strings={strings} language={language}>
                            <ToastProvider>{page.layout === undefined ? child : page.layout(child)}</ToastProvider>
                        </ShellProvider>
                    );
                }}
            </App>,
        );
    },
});
