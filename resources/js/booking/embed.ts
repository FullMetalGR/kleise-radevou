import { boot } from './boot';
import css from './embed.css?inline';
import './embed.css';

/*
 * The bundle's entry: the script a tenant pastes. It reads its own script element at import
 * time, which is why this is a classic script and not a module, and self-boots. The
 * stylesheet is imported twice on purpose: the inline copy rides inside this bundle, which is
 * the one file a tenant loads, and the plain import makes the build write the same stylesheet
 * beside it as embed.css, which the containment audit reads.
 */
boot({
    document,
    script: document.currentScript,
    global: window,
    fetch: (input, init) => fetch(input, init),
    css,
});
