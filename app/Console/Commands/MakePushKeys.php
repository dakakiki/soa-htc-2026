<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * The one-off that makes this installation's identity to a push service.
 *
 * 🔴 Run ONCE per installation, and keep the answer. The pair is what says "this
 * is the same sender as last time" (VAPID, RFC 8292) — change it and every
 * browser already subscribed refuses the next notification, silently, and every
 * coordinator has to turn notifications on again. There is nothing to recover it
 * from: it is not derived from anything.
 *
 * And no account anywhere. This is a key pair generated on the machine, not a
 * credential fetched from Google or anyone else.
 */
class MakePushKeys extends Command
{
    protected $signature = 'push:keys';

    protected $description = 'Generate the VAPID key pair for web push, to paste into .env';

    public function handle(): int
    {
        if (config('push.vapid.public')) {
            $this->warn('This installation already has a VAPID key.');
            $this->line('Replacing it makes every browser already subscribed stop accepting notifications,');
            $this->line('and each coordinator has to turn them on again. Clear VAPID_PUBLIC_KEY first if');
            $this->line('that is really what you want.');

            return self::FAILURE;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (Throwable $e) {
            $this->error('Could not generate a key: '.$e->getMessage());
            /*
             * 🪤 Almost always OpenSSL not finding its own configuration rather
             * than anything about this application — the message it gives for
             * that says only "unable to create the key", which sends people
             * looking in the wrong place.
             */
            $this->line('');
            $this->line('If that mentions OpenSSL, it is usually a missing openssl.cnf. On Windows:');
            $this->line('  set OPENSSL_CONF=C:\\wamp64\\bin\\php\\php8.3.29\\extras\\ssl\\openssl.cnf');

            return self::FAILURE;
        }

        $this->info('Paste these two lines into .env, then run: php artisan config:clear');
        $this->line('');
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('');
        $this->line('The PRIVATE one signs every notification. It belongs in .env beside APP_KEY');
        $this->line('and nowhere else — not in the repository, not in a chat message.');

        return self::SUCCESS;
    }
}
