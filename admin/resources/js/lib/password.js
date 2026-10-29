// Password generator backed by the browser's crypto RNG.
// No look-alike characters (0 O 1 l I) and only symbols that survive being typed
// into a mail client, with at least one character from every group.
const GROUPS = [
    'abcdefghijkmnopqrstuvwxyz',
    'ABCDEFGHJKLMNPQRSTUVWXYZ',
    '23456789',
    '!@#$%*-_=+',
];

// Uniform random integer in [0, max), without modulo bias.
function randomInt(max) {
    const limit = Math.floor(0x100000000 / max) * max;
    const buffer = new Uint32Array(1);
    do {
        crypto.getRandomValues(buffer);
    } while (buffer[0] >= limit);
    return buffer[0] % max;
}

export function generatePassword(length = 16) {
    const all = GROUPS.join('');
    const chars = GROUPS.map((group) => group[randomInt(group.length)]);

    while (chars.length < length) {
        chars.push(all[randomInt(all.length)]);
    }

    // Fisher-Yates shuffle so the guaranteed characters are not always first.
    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomInt(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    return chars.join('');
}
