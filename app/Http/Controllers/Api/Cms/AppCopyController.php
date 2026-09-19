<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Models\AppCopy;
use App\Domain\Cms\Support\AppScreens;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Website → Mobile: the installed application's own words (ADR-0133).
 *
 * Screens are not editable here — they come from {@see AppScreens}, in code, and
 * so does the list of keys each one offers. What an administrator saves is an
 * override per key, and clearing a box deletes the row rather than storing an
 * empty string.
 *
 * 🪤 No defaults are sent from here, and none are stored. The words a screen
 * ships with live in the SPA's own catalogue (`en.ts`), which is the same file
 * the screen reads them from and the same file the editor shows as each box's
 * placeholder. A copy of them in PHP would be a second set to keep in step, and
 * the first time the two disagreed the editor would be advertising a default
 * that no screen draws.
 */
class AppCopyController extends Controller
{
    /**
     * The registry the editor builds its tabs from, plus whatever is stored.
     *
     * Shipping the registry rather than a copy in the SPA keeps one declaration
     * for the form and for the validation — as the layout editor does.
     */
    public function index(): JsonResponse
    {
        $this->authorize('cms.manage');

        $screens = [];

        foreach (AppScreens::all() as $key => $screen) {
            $fields = [];

            foreach ($screen['fields'] as $i18nKey => $label) {
                $fields[] = ['key' => $i18nKey, 'label' => $label];
            }

            $screens[] = [
                'key' => $key,
                'label' => $screen['label'],
                'path' => $screen['path'],
                'description' => $screen['description'],
                'fields' => $fields,
            ];
        }

        return response()->json([
            'screens' => $screens,
            'values' => self::values(),
        ]);
    }

    /**
     * Save one screen's boxes.
     *
     * The whole screen arrives every time, so a key the payload leaves out is
     * one the screen does not offer — not one the administrator cleared. What
     * clearing looks like is an empty string, and that deletes the row.
     */
    public function update(Request $request, string $screen): JsonResponse
    {
        $this->authorize('cms.manage');

        abort_unless(AppScreens::has($screen), 404);

        $allowed = AppScreens::keysFor($screen);

        $values = $request->validate([
            'values' => ['present', 'array'],
            'values.*' => ['nullable', 'string', 'max:1000'],
        ])['values'];

        /*
         * 🔴 Only this screen's keys. Without the check, one tab could write
         * another tab's line — or a key no screen reads at all, which would then
         * sit in the table with nothing left to show it.
         */
        foreach (array_keys($values) as $key) {
            abort_unless(in_array($key, $allowed, true), 422, 'Unknown field for this screen.');
        }

        DB::transaction(function () use ($allowed, $values): void {
            foreach ($allowed as $key) {
                $value = trim((string) ($values[$key] ?? ''));

                if ($value === '') {
                    AppCopy::query()->where('key', $key)->delete();

                    continue;
                }

                AppCopy::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        return response()->json([
            'values' => self::values(),
        ]);
    }

    /**
     * What the application itself reads: the overrides, and nothing else.
     *
     * Public because the first screen of the application is — `/app` is reached
     * by tapping an icon, before anybody has signed in or entered a candidate
     * number. It publishes only what an administrator typed to be shown on a
     * screen anyone can open, so there is nothing here to withhold.
     *
     * 🪤 Filtered through the registry on the way out as well as on the way in.
     * A key that stops being offered — a screen reworded, a line dropped —
     * leaves its row behind, and without this the application would go on
     * drawing a line the editor no longer shows anybody.
     */
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'values' => self::values(),
        ]);
    }

    /**
     * The overrides as the wire carries them: an OBJECT, keyed by i18n key.
     *
     * 🪤 The cast is not decoration. PHP writes an empty array as `[]`, so an
     * installation that has rewritten nothing — which is every installation
     * until somebody opens the screen — served `{"values":[]}` where the client
     * is typed for a map. Nothing broke, because a missing key on an array reads
     * as `undefined` just as it does on an object and every line falls back to
     * the catalogue; but the common case was the one shape the contract did not
     * describe, which is how a type stops being believed.
     */
    private static function values(): object
    {
        return (object) self::stored();
    }

    /**
     * The stored overrides, keyed by i18n key, minus anything the registry no
     * longer offers.
     *
     * @return array<string, string>
     */
    private static function stored(): array
    {
        $offered = AppScreens::allKeys();

        return AppCopy::query()
            ->whereIn('key', $offered)
            ->pluck('value', 'key')
            ->all();
    }
}
