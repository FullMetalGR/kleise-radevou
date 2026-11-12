/*
 * A customer's tags: each word a small pill on the muted ground, in the order the profile holds
 * them. The words are the business's own, never keys. The list's name cell and the page's head
 * each place them in their own row beside what else that row carries.
 */

export function TagChips({ tags }: { tags: readonly string[] }) {
    return (
        <>
            {tags.map((tag) => (
                <span key={tag} className="admin-tag inline-flex rounded-full bg-muted px-2 py-0.5 text-xs">
                    {tag}
                </span>
            ))}
        </>
    );
}
