// Colored initials avatars. The color is derived from the address, so a mailbox
// keeps the same one everywhere (mailbox list, dashboard, webmail).
export const AVATAR_COLORS = [
    'bg-rose-500', 'bg-amber-500', 'bg-emerald-500', 'bg-sky-500', 'bg-blue-500', 'bg-pink-500', 'bg-teal-500',
];

export function avatarClass(seed) {
    const source = String(seed ?? '').toLowerCase();
    let hash = 0;
    for (let i = 0; i < source.length; i++) {
        hash = (hash * 31 + source.charCodeAt(i)) >>> 0;
    }
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

// Two letters: first letters of a two-word name, else of a "john.doe" style address, else its first two letters.
export function mailboxInitials(email, name) {
    const words = String(name ?? '').trim().split(/\s+/).filter(Boolean);
    if (words.length > 1) return (words[0][0] + words[1][0]).toUpperCase();

    const local = String(email ?? '').split('@')[0];
    const parts = local.split(/[._+-]+/).filter(Boolean);
    if (parts.length > 1) return (parts[0][0] + parts[1][0]).toUpperCase();

    return local.slice(0, 2).toUpperCase() || '?';
}
