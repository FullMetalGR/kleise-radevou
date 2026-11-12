import type { BusinessMap } from './model';
import { type SettingsKey, useSettings } from './words';

/*
 * The map's line under the address fields of the Booking page section: nothing while there is
 * no map to make, a line while the queued job prepares it, the stored image once it is ready,
 * and what to do when it could not be made. It has no control: the settings save that changes
 * the address is the control.
 */

/* The line's words, keys of the settings section's map group. */

const PENDING = 'map.pending' satisfies SettingsKey;

/**
 * The failed line (decision 18): shown on a save whose address the job could not place and after
 * a job that threw and settled failed alike, so it does not tell an owner whose address is right
 * to save it again, which queues nothing.
 */
export const FAILED = 'map.failed' satisfies SettingsKey;

const ALT = 'map.alt' satisfies SettingsKey;

export function MapLine({ map }: { map: BusinessMap }) {
    const words = useSettings();

    switch (map.state) {
        case 'none':
            return null;
        case 'pending':
            return <p className="admin-settings-map text-sm text-muted-foreground sm:col-span-2">{words.text(PENDING)}</p>;
        case 'failed':
            return <p className="admin-settings-map text-sm text-destructive sm:col-span-2">{words.text(FAILED)}</p>;
        case 'ready':
            return (
                <div className="admin-settings-map sm:col-span-2">
                    <img className="aspect-[2/1] w-full max-w-md rounded-md object-cover" src={map.image.url} alt={words.text(ALT)} width={640} height={320} />
                </div>
            );
    }
}
