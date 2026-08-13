<?php

return [
    /*
     * Locale used when no other is resolved.
     */
    'default' => env('APP_LOCALE', 'en'),

    /*
     * Locale used when a translation key is missing in the active locale.
     */
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),

    /*
     * Explicit allow-list of enabled locales. Adding a language later means
     * adding translations and enabling it here — not changing the schema or
     * hard-coded UI. English is the only initially active locale.
     */
    'supported' => ['en'],
];
