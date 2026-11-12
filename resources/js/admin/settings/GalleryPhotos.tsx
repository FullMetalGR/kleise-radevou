import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { FieldControl } from '@/components/ui/field';
import { FILE_INPUT } from './ImageSlot';
import { IMAGE_TYPES, type Limits, type StoredImage } from './model';
import { type SettingsKey, useSettings } from './words';

/*
 * The gallery on the Booking page section: the stored photos in the order the row keeps them, each
 * with its own Remove, the count against the capacity, and a chooser with Add photo, both disabled
 * once the gallery is full. Each add and each removal is its own write, apart from the settings
 * save, as the logo's are; the pressed button alone is busy, and while any write is in flight
 * every other control here is disabled with the reason stated beside it.
 *
 * The file input is the Field shell's control, and it stays where it is on a full gallery,
 * disabled with Add photo, so the control does not vanish under the pointer: the server's own
 * refusal sentence then stands under it and describes it, read before the help line, so the limit
 * is told before a press rather than after one.
 */

/**
 * GalleryController::FULL, the key the server refuses a photo past the capacity with, its
 * :capacity the page's limits.capacity spelled in words by the server.
 */
const FULL = 'images.refusals.full' satisfies SettingsKey;

type Props = {
    control: FieldControl;
    gallery: StoredImage[];
    /** The capacity, Settings::GALLERY_CAPACITY, as a figure and in words: the page's limits. */
    capacity: Limits['capacity'];
    adding: boolean;
    removing: boolean;
    /** The sentence for whatever write is in flight on the page, this block's own included, or null. */
    wait: string | null;
    onAdd: (file: File) => void;
    onRemove: (name: string) => void;
};

export function GalleryPhotos({ control, gallery, capacity, adding, removing, wait, onAdd, onRemove }: Props) {
    const words = useSettings();
    const [chosen, setChosen] = useState<File | null>(null);
    const [pressed, setPressed] = useState<string | null>(null);
    const held = wait !== null;
    const count = gallery.length;
    const full = count >= capacity.count;
    // The full sentence's id, which the chooser's description names first on a full gallery.
    const fullId = `${control.id}-full`;

    return (
        <div className="admin-settings-gallery flex flex-col gap-2 sm:col-span-2">
            <p className="admin-settings-gallery__count text-sm text-muted-foreground">
                {words.text('gallery.count', { count: String(count), capacity: String(capacity.count) })}
            </p>
            {count > 0 && (
                <ul className="admin-settings-gallery__grid grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {gallery.map((photo, index) => {
                        const mine = removing && pressed === photo.name;

                        return (
                            <li key={photo.name} className="admin-settings-gallery__photo flex flex-col items-start gap-1" data-name={photo.name}>
                                <img className="admin-settings-gallery__image aspect-square w-full rounded-md object-cover" src={photo.url} alt={words.text('gallery.photo_alt', { n: String(index + 1), count: String(count) })} />
                                <Button
                                    destructive
                                    variant="tertiary"
                                    className="admin-settings-gallery-remove"
                                    disabled={held && !mine}
                                    busy={mine}
                                    busyLabel={words.text('gallery.removing')}
                                    onClick={() => {
                                        setPressed(photo.name);
                                        onRemove(photo.name);
                                    }}
                                >
                                    {words.text('gallery.remove')}
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <input
                    {...control}
                    aria-describedby={full ? [fullId, control['aria-describedby']].filter((id) => id !== undefined).join(' ') : control['aria-describedby']}
                    type="file"
                    className={`admin-settings-gallery__file ${FILE_INPUT}`}
                    accept={IMAGE_TYPES}
                    disabled={full || adding || removing || held}
                    onChange={(event) => setChosen(event.target.files?.[0] ?? null)}
                />
                <Button
                    className="admin-settings-gallery-add"
                    disabled={full || chosen === null || (held && !adding)}
                    busy={adding}
                    busyLabel={words.text('gallery.adding')}
                    onClick={() => {
                        if (chosen !== null) {
                            onAdd(chosen);
                        }
                    }}
                >
                    {words.text('gallery.add')}
                </Button>
            </div>
            {full && (
                <p id={fullId} className="admin-settings-gallery__full text-sm text-muted-foreground">
                    {words.text(FULL, { capacity: capacity.spelled })}
                </p>
            )}
            {held && (
                <p className="admin-settings-gallery__wait text-sm text-muted-foreground" role="status">
                    {wait}
                </p>
            )}
        </div>
    );
}
