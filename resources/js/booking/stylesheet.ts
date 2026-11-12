/*
 * The bundle carries its stylesheet and installs it itself, because a tenant pastes one script
 * tag and nothing else. Installed once per document, found by attribute rather than by module
 * state, so a second execution of the bundle adds nothing. It goes at the end of head, after
 * the host's own stylesheets, and every rule in it carries a class, so an inline declaration
 * on the mount element still beats it: that is the theming path.
 */

export const STYLESHEET_ATTRIBUTE = 'data-kr-stylesheet';

export function installStylesheet(doc: Document, css: string): void {
    if (doc.head.querySelector(`style[${STYLESHEET_ATTRIBUTE}]`) !== null) {
        return;
    }

    const style = doc.createElement('style');

    style.setAttribute(STYLESHEET_ATTRIBUTE, '');
    style.textContent = css;
    doc.head.appendChild(style);
}
