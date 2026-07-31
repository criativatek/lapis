/**
 * Renders one CHANGELOG.md bullet's minimal markdown (**bold**, `code`) as
 * safe HTML. Content always comes from CHANGELOG.md in this repo — never
 * user input — so escaping first and interpolating tags after is enough.
 */
export function formatChangelogItem(text: string): string {
    const escaped = text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    return escaped
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/`(.+?)`/g, '<code>$1</code>');
}
