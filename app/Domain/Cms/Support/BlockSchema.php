<?php

declare(strict_types=1);

namespace App\Domain\Cms\Support;

use App\Domain\Cms\Enums\BlockType;
use Illuminate\Validation\Rule;

/**
 * What each block type is made of (ADR-0043).
 *
 * One declaration serves two masters: the admin form is built from `fields()`,
 * and the payload is validated by `rules()`. Keeping them together is the point
 * — a field the form offers but validation rejects, or the reverse, is the usual
 * way a JSON payload rots.
 */
final class BlockSchema
{
    /**
     * Button looks, bound to the brand palette. Deliberately an enum and not a
     * colour picker: the legacy module carried a free `back_color`, which is how
     * a designed page turns motley six months after launch. The palette itself
     * is changed in one place, in Theme settings.
     */
    public const BUTTON_STYLES = ['primary', 'navy', 'amber', 'outline', 'link'];

    /**
     * Where a button may point. Same rule as a menu item (ADR-0042): internal
     * targets are foreign keys, so renaming a slug moves the link with it, and
     * only `url` carries a literal address. `file` is a document from the media
     * library — the category document is downloaded, not visited.
     */
    public const TARGET_TYPES = ['page', 'post', 'category', 'route', 'file', 'url'];

    /**
     * Season gates a button may be subject to. A button with no gate is governed
     * by its own switch alone; `competition` also requires an active competition
     * quiz, `sample` its sample counterpart. This is the data half of the rule
     * that out of season the competition entry disappears whatever the admin set.
     */
    public const GATES = ['competition', 'sample'];

    /** How many of this type a zone may hold; null means no limit. */
    public static function maxInstances(BlockType $type): ?int
    {
        return match ($type) {
            // Three hero sections are not a choice, they are a mistake.
            BlockType::Hero, BlockType::Contact, BlockType::News => 1,
            // Chrome and screen copy: one record per zone, always.
            BlockType::Header, BlockType::Footer, BlockType::Login => 1,
            default => null,
        };
    }

    /** Whether this type uses the block's image reference. */
    public static function usesImage(BlockType $type): bool
    {
        return in_array($type, [
            BlockType::Hero,
            BlockType::Coordinators,
            BlockType::ImageBand,
        ], true);
    }

    /**
     * The editable shape of a type, for the admin form.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(BlockType $type): array
    {
        return match ($type) {
            BlockType::Hero => [
                self::text('eyebrow', 'Label above the heading', 60),
                self::text('title_accent', 'Heading, accented word', 40),
                self::text('title', 'Heading', 120),
                self::rich('lead', 'Intro paragraph', 1600),
                self::buttons(),
            ],
            BlockType::Notice => [
                self::text('title', 'Heading', 120),
                self::list('rules', 'Rules', [
                    self::text('marker', 'Marker', 4),
                    self::rich('text', 'Rule', 1200),
                ], 6),
                self::rich('footnote', 'Consequence', 1600),
            ],
            BlockType::Category => [
                self::text('eyebrow', 'Label above the heading', 60),
                self::text('title', 'Heading', 120),
                self::rich('lead', 'Intro paragraph', 1600),
                self::list('groups', 'Groups', [
                    self::text('numeral', 'Numeral', 4),
                    self::text('title', 'Title', 120),
                    self::rich('text', 'Description', 1200),
                ], 4),
                self::buttons(),
            ],
            BlockType::SplitCta => [
                self::text('eyebrow', 'Label above the columns', 60),
                self::list('columns', 'Columns', [
                    self::enum('accent', 'Accent', ['primary', 'amber']),
                    self::text('title', 'Heading', 120),
                    self::text('note', 'Small print', 120),
                    self::rich('text', 'Paragraph', 1600),
                    self::button('button', 'Button'),
                ], 2),
            ],
            BlockType::Coordinators => [
                self::text('eyebrow', 'Label above the heading', 60),
                self::text('title', 'Heading', 120),
                self::rich('lead', 'Intro paragraph', 1600),
                self::buttons(),
            ],
            BlockType::Contact => [
                self::text('title', 'Heading', 120),
                self::rich('lead', 'Intro paragraph', 1600),
                self::list('links', 'Links', [
                    self::text('label', 'Label', 60),
                    self::text('value', 'Shown text', 160),
                    self::text('url', 'Address', 500),
                ], 4),
            ],
            BlockType::News => [
                self::text('title', 'Heading', 120),
                self::number('limit', 'How many articles', 1, 6),
            ],
            BlockType::ImageBand => [
                self::text('caption_label', 'Caption label', 40),
                self::text('caption', 'Caption', 200),
            ],
            // The header's only setting. The logo comes from Theme, and the
            // sign-in button is not offered: what it says depends on whether the
            // visitor is signed in, so it is not a value an admin can set.
            BlockType::Header => [
                self::menu('menu', 'Navigation menu'),
            ],
            // The footer is a paragraph and however many link columns the site
            // needs. Columns are a list rather than two fixed slots because the
            // owner expects the count to grow.
            BlockType::Footer => [
                self::rich('text', 'Text under the logo', 1600),
                self::list('columns', 'Link columns', [
                    self::text('title', 'Column heading', 60),
                    self::menu('menu', 'Menu'),
                ], 4),
                // The whole line is the admin's, © included; only the year is not.
                // A typed year would be wrong every January and nobody would notice
                // for months, so it is a token the page fills in.
                self::text('copyright', 'Copyright line', 160, 'Write {year} where the current year should appear — for example: © {year} SOA HTC'),
            ],
            // The sign-in screen's words. Field labels and the button are NOT here:
            // they are interface, and an admin editing "E-mail" into something else
            // breaks the form rather than improving the page.
            BlockType::Login => [
                self::text('eyebrow', 'Label above the heading', 60),
                self::text('title', 'Heading', 120),
                self::rich('lead', 'Paragraph', 1600),
            ],
        };
    }

    /**
     * The request key the payload travels under.
     *
     * 🪤 Not `data`: a `JsonResource` whose array already has a `data` key is
     * treated as pre-wrapped, so the response would come back unwrapped while
     * every other endpoint returns `{"data": …}`. Naming the payload `content`
     * keeps one shape across the API.
     */
    public const KEY = 'content';

    /**
     * Validation for a payload of this type. Anything the schema does not name
     * is rejected by the controller, so the column cannot quietly collect junk.
     *
     * @return array<string, mixed>
     */
    public static function rules(BlockType $type): array
    {
        $rules = [];

        foreach (self::fields($type) as $field) {
            $rules += self::rulesFor(self::KEY.'.'.$field['key'], $field);
        }

        return $rules;
    }

    /** The keys a payload of this type may carry. @return list<string> */
    public static function allowedKeys(BlockType $type): array
    {
        return array_map(static fn (array $f): string => $f['key'], self::fields($type));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function rulesFor(string $path, array $field): array
    {
        return match ($field['kind']) {
            'text' => [$path => ['nullable', 'string', 'max:'.$field['max']]],
            // Rich text is admin-authored markup, so the cap has to leave room for
            // the tags as well as the words.
            'textarea', 'rich' => [$path => ['nullable', 'string', 'max:'.$field['max']]],
            'number' => [$path => ['nullable', 'integer', 'min:'.$field['min'], 'max:'.$field['max']]],
            'enum' => [$path => ['nullable', Rule::in($field['options'])]],
            // A foreign key, like a menu item's target (ADR-0042): renaming the
            // menu moves the reference with it, and a deleted menu leaves a
            // reference that resolves to nothing rather than to a stale copy.
            'menu' => [$path => ['nullable', 'integer', Rule::exists('cms_menus', 'id')]],
            'button' => self::buttonRules($path),
            'buttons' => [$path => ['sometimes', 'array', 'max:3']]
                + self::buttonRules($path.'.*'),
            'list' => [$path => ['sometimes', 'array', 'max:'.$field['max']]]
                + array_reduce(
                    $field['item'],
                    static fn (array $carry, array $sub): array => $carry + self::rulesFor($path.'.*.'.$sub['key'], $sub),
                    [],
                ),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function buttonRules(string $path): array
    {
        return [
            $path => ['nullable', 'array'],
            $path.'.label' => ['required_with:'.$path, 'string', 'max:80'],
            $path.'.style' => ['required_with:'.$path, Rule::in(self::BUTTON_STYLES)],
            $path.'.status' => ['required_with:'.$path, 'boolean'],
            $path.'.gate' => ['nullable', Rule::in(self::GATES)],
            $path.'.target' => ['required_with:'.$path, 'array'],
            $path.'.target.type' => ['required_with:'.$path.'.target', Rule::in(self::TARGET_TYPES)],
            // A foreign key for internal targets; `route`, `url` and `file` use
            // the value instead.
            $path.'.target.id' => ['nullable', 'integer'],
            $path.'.target.value' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * A single-line field. `$hint` is shown under the input for a field whose
     * label cannot carry the whole rule — a token the admin has to know about,
     * for instance.
     *
     * @return array<string, mixed>
     */
    private static function text(string $key, string $label, int $max, ?string $hint = null): array
    {
        return array_filter(
            ['key' => $key, 'kind' => 'text', 'label' => $label, 'max' => $max, 'hint' => $hint],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /** @return array<string, mixed> */
    private static function textarea(string $key, string $label, int $max): array
    {
        return ['key' => $key, 'kind' => 'textarea', 'label' => $label, 'max' => $max];
    }

    /** A paragraph the admin writes in the editor: bold, italic, links, lists. */
    /** @return array<string, mixed> */
    private static function rich(string $key, string $label, int $max): array
    {
        return ['key' => $key, 'kind' => 'rich', 'label' => $label, 'max' => $max];
    }

    /** @return array<string, mixed> */
    private static function number(string $key, string $label, int $min, int $max): array
    {
        return ['key' => $key, 'kind' => 'number', 'label' => $label, 'min' => $min, 'max' => $max];
    }

    /**
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private static function enum(string $key, string $label, array $options): array
    {
        return ['key' => $key, 'kind' => 'enum', 'label' => $label, 'options' => $options];
    }

    /** A reference to a CMS menu; the editor offers the menus that exist. */
    /** @return array<string, mixed> */
    private static function menu(string $key, string $label): array
    {
        return ['key' => $key, 'kind' => 'menu', 'label' => $label];
    }

    /**
     * @param  list<array<string, mixed>>  $item
     * @return array<string, mixed>
     */
    private static function list(string $key, string $label, array $item, int $max): array
    {
        return ['key' => $key, 'kind' => 'list', 'label' => $label, 'item' => $item, 'max' => $max];
    }

    /** @return array<string, mixed> */
    private static function button(string $key, string $label): array
    {
        return ['key' => $key, 'kind' => 'button', 'label' => $label];
    }

    /** @return array<string, mixed> */
    private static function buttons(): array
    {
        return ['key' => 'buttons', 'kind' => 'buttons', 'label' => 'Buttons'];
    }
}
