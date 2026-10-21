import DOMPurify from 'dompurify';

// Everything that can run code, load another document, submit data or restyle
// the surrounding app is removed. Inline `style` is kept so newsletters still
// look right, remote images are kept (see README, "Security notes").
const FORBID_TAGS = [
    'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
    'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base',
    'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'dialog',
];

// Only link and image URL schemes that cannot execute: http(s), mailto, tel, cid already
// rewritten to data: by the server, and data:image/* for inline pictures.
const ALLOWED_URI = /^(?:(?:https?|mailto|tel):|data:image\/(?:png|jpe?g|gif|webp|bmp|avif);base64,|#)/i;

const purify = DOMPurify();

purify.addHook('afterSanitizeAttributes', (node) => {
    if (node.tagName === 'A') {
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer nofollow');
    }

    // `position: fixed/absolute` and negative z-index let a message paint over the app UI.
    const style = node.getAttribute?.('style');
    if (style && /position\s*:\s*(fixed|sticky|absolute)|z-index|expression\s*\(|url\s*\(\s*['"]?\s*javascript:/i.test(style)) {
        node.setAttribute('style', style
            .replace(/position\s*:\s*[^;]+;?/gi, '')
            .replace(/z-index\s*:\s*[^;]+;?/gi, '')
            .replace(/[^;]*expression\s*\([^;]*;?/gi, '')
            .replace(/[^;]*javascript:[^;]*;?/gi, ''));
    }
});

export function sanitizeEmail(html) {
    return purify.sanitize(html ?? '', {
        FORBID_TAGS,
        FORBID_ATTR: ['srcset', 'formaction', 'action', 'ping'],
        ALLOWED_URI_REGEXP: ALLOWED_URI,
        ADD_ATTR: ['align', 'valign'],
        ALLOW_DATA_ATTR: false,
        ALLOW_ARIA_ATTR: false,
    });
}
