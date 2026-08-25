<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Cms;

use App\Domain\Cms\Enums\BlockType;
use App\Domain\Cms\Models\LayoutBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Support\BlockSchema;
use App\Domain\Cms\Support\LayoutZones;
use App\Http\Controllers\Controller;
use App\Http\Resources\CmsLayoutBlockResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The layout editor's endpoints (ADR-0043).
 *
 * Zones are not editable here — they come from `LayoutZones`, in code. What the
 * admin arranges is which blocks a zone holds, in what order, and whether each
 * is switched on.
 */
class LayoutController extends Controller
{
    /**
     * The registry the editor builds its forms from: the zones, the types each
     * accepts, and the fields of every type. Shipping this rather than a copy in
     * the SPA keeps one declaration for the form and the validation.
     *
     * @return array<string, mixed>
     */
    public function zones(): array
    {
        $this->authorize('cms.manage');

        $zones = [];

        foreach (LayoutZones::all() as $key => $zone) {
            $zones[] = [
                'key' => $key,
                'label' => $zone['label'],
                'description' => $zone['description'],
                // A zone that holds one record is edited as a form, not as a list
                // of sections; the editor needs to know which it is looking at.
                'is_single' => LayoutZones::isSingle($key),
                'types' => array_map(static fn (BlockType $type): array => [
                    'key' => $type->value,
                    'label' => $type->label(),
                    'max' => BlockSchema::maxInstances($type),
                    'uses_image' => BlockSchema::usesImage($type),
                    'fields' => BlockSchema::fields($type),
                ], $zone['types']),
            ];
        }

        return [
            'data' => [
                'zones' => $zones,
                'button_styles' => BlockSchema::BUTTON_STYLES,
                'target_types' => BlockSchema::TARGET_TYPES,
                'gates' => BlockSchema::GATES,
                // Options for every `menu` field, shipped with the registry so the
                // form does not need a second request to know what it may offer.
                'menus' => Menu::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'slug'])
                    ->map(fn (Menu $menu): array => [
                        'id' => $menu->id,
                        'name' => $menu->name,
                        'slug' => $menu->slug,
                    ])
                    ->all(),
            ],
        ];
    }

    /** Everything in the zone, switched off included — this is the editor. */
    public function index(string $zone): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');
        $this->assertZone($zone);

        return CmsLayoutBlockResource::collection(
            LayoutBlock::query()->inZone($zone)->with('image')->get()
        );
    }

    public function store(Request $request, string $zone): CmsLayoutBlockResource
    {
        $this->authorize('cms.manage');
        $this->assertZone($zone);

        $type = $this->validatedType($request, $zone);
        $this->assertRoomFor($zone, $type, null);

        $block = LayoutBlock::query()->create([
            'zone' => $zone,
            'type' => $type,
            'status' => $request->boolean('status', true),
            'image_media_id' => $this->validatedImage($request),
            'data' => $this->validatedData($request, $type),
            // New sections land at the end; the editor reorders by dragging.
            'position' => (int) LayoutBlock::query()->where('zone', $zone)->max('position') + 1,
        ]);

        return CmsLayoutBlockResource::make($block->load('image'));
    }

    /**
     * A block's type is fixed once created. Changing it would leave the payload
     * describing a shape the new type cannot read, and there is no sensible
     * translation between, say, a hero and a news strip — deleting and adding is
     * both clearer and honest about what happens to the content.
     */
    public function update(Request $request, LayoutBlock $block): CmsLayoutBlockResource
    {
        $this->authorize('cms.manage');

        $payload = ['status' => $request->boolean('status', $block->status)];

        // A status-only request (the inline toggle) leaves the rest untouched.
        if ($request->has(BlockSchema::KEY)) {
            $payload['data'] = $this->validatedData($request, $block->type);
        }

        if ($request->has('image_media_id')) {
            $payload['image_media_id'] = $this->validatedImage($request);
        }

        $block->update($payload);

        return CmsLayoutBlockResource::make($block->refresh()->load('image'));
    }

    public function destroy(LayoutBlock $block): Response
    {
        $this->authorize('cms.manage');

        $block->delete();

        return response()->noContent();
    }

    /**
     * The whole order in one request, as the menu editor does it and for the
     * same reason: dragging one section rewrites the position of several.
     */
    public function saveOrder(Request $request, string $zone): AnonymousResourceCollection
    {
        $this->authorize('cms.manage');
        $this->assertZone($zone);

        $validated = $request->validate([
            'blocks' => ['present', 'array'],
            'blocks.*' => ['integer'],
        ]);

        $ids = array_map('intval', $validated['blocks']);
        $inZone = LayoutBlock::query()->where('zone', $zone)->pluck('id')->all();

        // An id from another zone would silently move a block across the site.
        if (array_diff($ids, $inZone) !== [] || count($ids) !== count($inZone)) {
            abort(422, __('messages.layout.order_mismatch'));
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $position => $id) {
                LayoutBlock::query()->whereKey($id)->update(['position' => $position + 1]);
            }
        });

        return $this->index($zone);
    }

    private function assertZone(string $zone): void
    {
        abort_unless(LayoutZones::exists($zone), 404);
    }

    private function validatedType(Request $request, string $zone): BlockType
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(BlockType::class)],
        ]);

        $type = BlockType::from($validated['type']);

        abort_unless(LayoutZones::accepts($zone, $type), 422, __('messages.layout.type_not_allowed'));

        return $type;
    }

    /** Singleton types refuse a second instance rather than letting the page rot. */
    private function assertRoomFor(string $zone, BlockType $type, ?int $ignoreId): void
    {
        $max = BlockSchema::maxInstances($type);

        if ($max === null) {
            return;
        }

        $count = LayoutBlock::query()
            ->where('zone', $zone)
            ->where('type', $type->value)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->count();

        abort_if($count >= $max, 422, __('messages.layout.type_limit', ['type' => $type->label(), 'max' => $max]));
    }

    private function validatedImage(Request $request): ?int
    {
        $validated = $request->validate([
            'image_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
        ]);

        return $validated['image_media_id'] ?? null;
    }

    /**
     * Validate the payload against its type, then keep only the keys that type
     * declares — so a field removed from the schema stops being written, and
     * nothing unnamed ever reaches the column.
     *
     * @return array<string, mixed>
     */
    private function validatedData(Request $request, BlockType $type): array
    {
        $validated = $request->validate(BlockSchema::rules($type));

        return Arr::only($validated[BlockSchema::KEY] ?? [], BlockSchema::allowedKeys($type));
    }
}
