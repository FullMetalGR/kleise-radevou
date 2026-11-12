import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { type FieldControl, HelpLine } from '@/components/ui/field';
import { useShell } from '@/lib/strings';
import { IMAGE_TYPES, type StoredImage } from './model';

/*
 * One stored image, what is there now, a file to replace it with, and Remove: the logo's and the
 * cover's on the Booking page section, and a staff member's photo on their detail page. The
 * upload and the removal are each their own write with their own button, apart from the settings
 * save, because a file that fails to land should not fail the currency. The pressed button alone
 * is busy; while any write is in flight, on this block or elsewhere on the page, every other
 * control here is disabled with the reason stated beside it.
 *
 * The file input is the Field shell's control: it takes the shell's attributes, so the label
 * points at it by id and the help line hangs off aria-describedby like every other field on the
 * page. The chooser accepts the three raster types the server reads from the bytes; the list here
 * only keeps the picker honest.
 *
 * The caller's block spells the slot's class names, admin-settings-logo, admin-settings-cover and
 * admin-staff-photo, so each is reached on its own; the words are the caller's too, but the two
 * busy labels every slot shares, which are the shell's.
 */

/**
 * A native file chooser drawn as the kit draws its controls: the chooser's button in the
 * secondary button's border, height and type, the chosen file's name beside it in the help
 * line's muted 12px. Shared by every file input of Settings and the staff page.
 */
export const FILE_INPUT =
    'text-xs text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-lg file:border file:border-border file:bg-background file:px-3 file:text-sm file:font-medium file:text-foreground hover:file:bg-muted disabled:cursor-not-allowed disabled:opacity-60';

/** What a slot says: the line where nothing is stored, and its three buttons. */
export type ImageSlotWords = { none: string; upload: string; replace: string; remove: string };

type Props = {
    /** The block class every class name of the slot is spelled from. */
    block: string;
    words: ImageSlotWords;
    /** The preview's sizing, which differs between a header mark and a wide photo. */
    preview: string;
    control: FieldControl;
    image: StoredImage | null;
    uploading: boolean;
    removing: boolean;
    /** The sentence for whatever write is in flight on the page, this block's own included, or null. */
    wait: string | null;
    onUpload: (file: File) => void;
    onRemove: () => void;
};

export function ImageSlot({ block, words, preview, control, image, uploading, removing, wait, onUpload, onRemove }: Props) {
    const shell = useShell();
    const [chosen, setChosen] = useState<File | null>(null);
    const held = wait !== null;

    return (
        <div className={`${block} flex flex-col gap-2 sm:col-span-2`}>
            {image === null ? (
                <HelpLine className={`${block}__none`}>{words.none}</HelpLine>
            ) : (
                <div className={`${block}__current flex items-center gap-3`}>
                    <img className={`${block}__image ${preview}`} src={image.url} alt="" />
                    <span className={`${block}__name text-xs text-muted-foreground`}>{image.name}</span>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-3">
                <input {...control} type="file" className={`${block}__file ${FILE_INPUT}`} accept={IMAGE_TYPES} disabled={uploading || removing || held} onChange={(event) => setChosen(event.target.files?.[0] ?? null)} />
                <Button
                    className={`${block}-upload`}
                    disabled={chosen === null || (held && !uploading)}
                    busy={uploading}
                    busyLabel={shell.text('shared.image.uploading')}
                    onClick={() => {
                        if (chosen !== null) {
                            onUpload(chosen);
                        }
                    }}
                >
                    {image === null ? words.upload : words.replace}
                </Button>
                {image !== null && (
                    <Button destructive variant="tertiary" className={`${block}-remove`} disabled={held && !removing} busy={removing} busyLabel={shell.text('shared.image.removing')} onClick={onRemove}>
                        {words.remove}
                    </Button>
                )}
            </div>
            {held && (
                <HelpLine className={`${block}__wait`} role="status">
                    {wait}
                </HelpLine>
            )}
        </div>
    );
}
