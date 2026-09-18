<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Communication\Models\PushSubscription;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Turning notifications on for one browser, and off again.
 *
 * 🔴 No permission on any of it. Anybody with an account can be sent a message,
 * so anybody with an account may ask to be told about one — a permission here
 * would have to be granted to everyone to mean anything, which is the same
 * reasoning as the inbox and the coordinator's screens (ADR-0104, ADR-0116).
 *
 * 🪤 The public key is served rather than built into the bundle. It belongs to
 * the installation, not to the code: a fresh deployment generates its own pair,
 * and a key baked into the JavaScript would be the previous one for ever.
 */
class PushController extends Controller
{
    /**
     * What the browser needs before it can subscribe — and whether there is any
     * point asking.
     *
     * `enabled` is false on an installation with no keys, so the screen can
     * leave the button out instead of offering something that cannot work.
     */
    public function key(): JsonResponse
    {
        $public = config('push.vapid.public');
        $enabled = is_string($public) && $public !== '' && is_string(config('push.vapid.private'));

        return response()->json(['data' => [
            'enabled' => $enabled,
            'key' => $enabled ? $public : null,
        ]]);
    }

    /**
     * Remember this browser.
     *
     * 🪤 Matched on the endpoint, never on the person. A browser that
     * re-subscribes — after permission was withdrawn and given again, or after
     * the service rotated its address — hands back the same endpoint, and
     * inserting a second row for it would deliver every later message twice.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $subscription = PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashFor($data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['data' => ['id' => $subscription->id]], 201);
    }

    /**
     * Forget it.
     *
     * 🪤 Deleting only where the row is also THEIRS. The endpoint is the only
     * thing the browser can name and it is not a secret — a stray or guessed one
     * must not unsubscribe somebody else's device.
     */
    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashFor($data['endpoint']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->noContent();
    }
}
