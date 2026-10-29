function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * `target` is either a plain URL or a Wayfinder route (`{ url, method }`, e.g.
 * `WebmailController.folders()`), whose method is used unless `options.method` is set.
 */
export async function fetchJson(target, options = {}) {
    const url = typeof target === 'string' ? target : target.url;
    const method = (options.method ?? (typeof target === 'string' ? 'GET' : target.method)).toUpperCase();

    const response = await fetch(url, {
        ...options,
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...options.headers,
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (! response.ok) {
        throw new Error(data.error || `Request failed (${response.status})`);
    }

    return data;
}
