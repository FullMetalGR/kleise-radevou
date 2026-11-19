import { Fragment } from 'react';

/*
 * The trail from a detail screen back to its list, a navigation region named by its label. Each
 * step before the last is a link in the deep primary ink; one the screen answers itself, such as
 * a return that asks first about unsaved edits, stops the browser's own navigation and hands the
 * press over. The last step is the page itself, in the ink, marked as the current page and never
 * a link. The slashes between the steps are drawn for the eye and hidden from a reader.
 */

type Crumb = {
    label: string;
    href?: string;
    onClick?: () => void;
};

type Props = {
    label: string;
    items: Crumb[];
};

function Step({ crumb, last }: { crumb: Crumb; last: boolean }) {
    if (last || crumb.href === undefined) {
        return (
            <span className={last ? 'admin-breadcrumb__current font-semibold text-foreground' : 'font-semibold'} aria-current={last ? 'page' : undefined}>
                {crumb.label}
            </span>
        );
    }

    const onClick = crumb.onClick;

    return (
        <a
            href={crumb.href}
            className="admin-breadcrumb__list focus-ring rounded-sm font-semibold text-primary-deep underline-offset-4 hover:underline"
            onClick={
                onClick === undefined
                    ? undefined
                    : (event) => {
                          event.preventDefault();
                          onClick();
                      }
            }
        >
            {crumb.label}
        </a>
    );
}

export function Breadcrumb({ label, items }: Props) {
    return (
        <nav className="admin-breadcrumb text-body-sm text-muted-foreground" aria-label={label}>
            <ol className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                {items.map((crumb, index) => (
                    <Fragment key={`${String(index)} ${crumb.label}`}>
                        {index > 0 && (
                            <li aria-hidden="true" className="select-none">
                                /
                            </li>
                        )}
                        <li className="min-w-0 wrap-break-word">
                            <Step crumb={crumb} last={index === items.length - 1} />
                        </li>
                    </Fragment>
                ))}
            </ol>
        </nav>
    );
}
