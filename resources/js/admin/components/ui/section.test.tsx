import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mount, type Mounted } from './harness';
import { Section } from './section';

/*
 * A form section: a form card named by its heading, an optional note beside the heading, then
 * its fields. The fields sit in two columns where the card itself is wide enough and in one
 * where it is not, whatever the window's width, so a form reads the same beside a wider sidebar.
 * The width the fields ask for is the card's from edge to edge: a container answers with the
 * width inside its padding, so the card holds none and its heading row and its fields hold the
 * form card's room between them. A danger section is marked by a red outline and a red heading.
 */

let view: Mounted;

beforeEach(() => {
    view = mount();
});

afterEach(() => view.unmount());

const q = (selector: string) => view.host.querySelector(selector);
const classesOf = (element: Element | null | undefined) => [...(element?.classList ?? [])];

/** A grid column count under any variant. */
const COLUMNS = /(^|:)grid-cols-/;

/** Padding on any side under any variant. */
const ROOM = /(^|:)p[xytblrse]?-/;

const root = () => q('section.admin-section');
const body = () => root()?.lastElementChild;
const roomOf = (element: Element | null | undefined) => classesOf(element).filter((name) => ROOM.test(name));

describe('the section', () => {
    it('is a form card named by its heading, and gives way in a row that holds it', () => {
        view.render(
            <Section id="profile" title="section-title-token">
                <p className="probe-field">field-token</p>
            </Section>,
        );

        const section = q('section.admin-section.admin-section--profile');
        const heading = q('h2#section-profile');

        expect(section?.getAttribute('aria-labelledby')).toBe('section-profile');
        expect(classesOf(section)).toEqual(expect.arrayContaining(['bg-card', 'rounded-tile', 'shadow-card', 'max-wide:rounded-tile-compact', 'flex', 'min-w-0', 'flex-col', 'gap-4']));
        expect(heading?.textContent).toBe('section-title-token');
        expect(classesOf(heading)).toContain('text-card-title');
        expect(body()?.querySelector('.probe-field')?.textContent).toBe('field-token');
    });

    it('is the one container its fields measure, from edge to edge: the card holds no room, and its heading row and its fields hold the form card\'s between them', () => {
        view.render(
            <Section id="profile" title="section-title-token">
                <p>field-token</p>
            </Section>,
        );

        const containers = [...view.host.querySelectorAll('*')].filter((element) => element.classList.contains('@container'));

        expect(containers).toEqual([root()]);
        expect(roomOf(root())).toEqual([]);
        expect(roomOf(root()?.firstElementChild)).toEqual(['px-6.5', 'pt-6', 'max-wide:px-5', 'max-wide:pt-5']);
        expect(roomOf(body())).toEqual(['px-6.5', 'pb-6.5', 'max-wide:px-5', 'max-wide:pb-5']);
    });

    it('holds its heading and its note in one row above the fields, and no note it is not given', () => {
        view.render(
            <Section id="details" title="section-title-token" meta="section-meta-token">
                <p>field-token</p>
            </Section>,
        );

        const head = root()?.firstElementChild;

        expect([...(head?.children ?? [])].map((el) => [el.tagName, el.textContent])).toEqual([
            ['H2', 'section-title-token'],
            ['P', 'section-meta-token'],
        ]);
        expect(head?.querySelector('p')?.classList.contains('admin-section__meta')).toBe(true);

        view.render(
            <Section key="plain" id="details" title="section-title-token">
                <p>field-token</p>
            </Section>,
        );

        expect(q('.admin-section__meta')).toBeNull();
    });

    it('lays its fields in two columns from the card\'s own width, not the window\'s', () => {
        view.render(
            <Section id="profile" title="section-title-token">
                <p>field-token</p>
            </Section>,
        );

        expect(classesOf(body())).toEqual(expect.arrayContaining(['grid', '@xl:grid-cols-2']));
        expect(classesOf(body()).filter((name) => COLUMNS.test(name))).toEqual(['@xl:grid-cols-2']);
    });

    it('keeps a wide section\'s fields on one column at any width', () => {
        view.render(
            <Section id="photos" title="section-title-token" wide>
                <p>field-token</p>
            </Section>,
        );

        expect(classesOf(body())).toContain('grid');
        expect(classesOf(body()).filter((name) => COLUMNS.test(name))).toEqual([]);
    });

    it('marks a danger section by a red outline and a red heading, and only a danger section', () => {
        view.render(
            <Section id="danger" title="section-title-token" danger>
                <p>field-token</p>
            </Section>,
        );

        expect(classesOf(root())).toEqual(expect.arrayContaining(['ring-[1.5px]', 'ring-destructive/40']));
        expect(classesOf(q('h2#section-danger'))).toContain('text-destructive');

        view.render(
            <Section key="plain" id="profile" title="section-title-token">
                <p>field-token</p>
            </Section>,
        );

        expect(classesOf(root())).not.toContain('ring-destructive/40');
        expect(classesOf(q('h2#section-profile'))).not.toContain('text-destructive');
    });

    it('keeps the hook class its place gives it on its root', () => {
        view.render(
            <Section id="danger" title="section-title-token" danger className="admin-settings-danger">
                <p>field-token</p>
            </Section>,
        );

        expect(classesOf(root())).toEqual(expect.arrayContaining(['admin-section', 'admin-section--danger', 'admin-settings-danger']));
    });
});
