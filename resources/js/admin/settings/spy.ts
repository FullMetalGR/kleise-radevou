/** A section of the page and where its card's top sits, in pixels from the top of the screen. */
export interface SectionTop {
    id: string;
    top: number;
}

/**
 * The section the page is reading: the last one whose card has reached the line, a top at the
 * line or above it; the first while none has, and none for a page with no sections.
 */
export function currentSection(sections: SectionTop[], line: number): string | null {
    let current = sections[0]?.id ?? null;

    for (const section of sections) {
        if (section.top <= line) {
            current = section.id;
        }
    }

    return current;
}
