<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Web push
    |--------------------------------------------------------------------------
    |
    | 🔴 There is no Firebase here, and none is needed. A browser hands back a
    | subscription whose `endpoint` already names the push service its own vendor
    | runs — Google's for Chrome, Mozilla's for Firefox, Apple's for Safari — and
    | the server simply posts to that address. What identifies the sender is
    | VAPID (RFC 8292): a key pair generated once, on this machine, with no
    | account anywhere. `minishlink/web-push` does not support FCM at all, and
    | the legacy path that did stopped working in June 2024.
    |
    | The pair is made with `php artisan push:keys`, which prints the two lines
    | to paste here. The PRIVATE key signs; leaking it lets somebody else send
    | notifications in this application's name, so it lives in `.env` beside the
    | application key and nowhere else.
    |
    | `subject` is a contact address the push service can use if something is
    | wrong with what is being sent. A `mailto:` or an https URL; the spec asks
    | for one and some services refuse without it.
    |
    */

    'vapid' => [
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', env('MAIL_FROM_ADDRESS') ? 'mailto:'.env('MAIL_FROM_ADDRESS') : null),
    ],

    /*
     * How many notifications one run of `messages:send` hands over.
     *
     * Lower than the mail batch on purpose: a push is a request per DEVICE
     * rather than per person, and a coordinator with a phone and a tablet is two.
     */
    'batch' => (int) env('PUSH_BATCH', 100),

];
