/*
 * The registry of mounted elements: a WeakSet on a global, shared across executions of the
 * bundle, because a tenant can paste the script tag twice and browsers do not deduplicate
 * classic scripts by URL. A module-scoped guard would reset with the second execution and the
 * second root would replace the first, destroying a half-filled form or a running countdown.
 *
 * The global is adopted with an instanceof check, not ??=. Another script on the tenant's page
 * can have set the same property to something else, and keeping that junk would throw on the
 * first .has() inside the import-time boot, mounting nothing anywhere. Treating a clobbered
 * value as absent risks at worst one duplicate mount.
 */

export const REGISTRY_KEY = '__krMounted';

type RegistryHost = { [REGISTRY_KEY]?: unknown };

export function adoptRegistry(host: object): WeakSet<Element> {
    const holder = host as RegistryHost;
    const existing = holder[REGISTRY_KEY];

    if (existing instanceof WeakSet) {
        return existing as WeakSet<Element>;
    }

    const registry = new WeakSet<Element>();

    holder[REGISTRY_KEY] = registry;

    return registry;
}
