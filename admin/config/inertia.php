<?php

return [

    /*
    | This panel is a plain client-rendered Inertia app (no ssr.js entry, no
    | Node SSR server), so SSR is off. Set INERTIA_SSR_ENABLED=true only if an
    | SSR bundle is ever added.
    */
    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', false),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'ensure_bundle_exists' => true,
    ],

];
