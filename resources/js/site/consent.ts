import { startConsent } from './consentBanner';

/*
 * The consent banner's entry: an entry of the admin build, linked by the central host's two root
 * views alone, beside the banner they render while the analytics tag's id is set. A module, so it
 * runs once the banner above it is parsed.
 */
startConsent({
    document,
    window,
    cookies: document,
    secure: window.location.protocol === 'https:',
    reload: () => window.location.reload(),
});
