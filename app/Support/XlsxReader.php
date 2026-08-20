<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * A tiny, dependency-free .xlsx reader — the counterpart to {@see XlsxWriter}.
 * Reads the first worksheet into a list of rows (each a list of cell strings,
 * indexed by column), resolving shared strings, inline strings and numbers.
 * Every value is returned as a string; the caller parses numbers as needed.
 * Enough for simple tabular imports (e.g. results: Student ID | Result | Qual).
 */
final class XlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * Cap on a single entry's *uncompressed* size, checked before decompressing —
     * a guard against zip bombs (a small archive that expands to gigabytes).
     * Generous for a results sheet; far below what would exhaust memory.
     */
    private const MAX_ENTRY_BYTES = 50 * 1024 * 1024;

    /**
     * Read the workbook's first worksheet as rows of cell strings. Gaps between
     * cells are filled with empty strings so a value always sits at its column
     * index (Student ID at 0, Result at 1, Qualification at 2).
     *
     * @return list<list<string>>
     */
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the uploaded spreadsheet.');
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheetXml = self::entryContents($zip, self::firstSheetName($zip));
            if ($sheetXml === false) {
                throw new RuntimeException('The spreadsheet has no readable worksheet.');
            }

            return self::rows($sheetXml, $shared);
        } finally {
            $zip->close();
        }
    }

    /**
     * Read one zip entry, refusing to decompress it if its uncompressed size
     * exceeds {@see MAX_ENTRY_BYTES} (zip-bomb guard). Returns false when the entry
     * is absent, mirroring ZipArchive::getFromName.
     */
    private static function entryContents(ZipArchive $zip, string $name): string|false
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return false;
        }
        if (($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('The spreadsheet is too large to process.');
        }

        return $zip->getFromName($name);
    }

    /**
     * The shared string table (`xl/sharedStrings.xml`), if present. Each entry may
     * be a single run or several `<r><t>` runs, which are concatenated.
     *
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = self::entryContents($zip, 'xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $sst = self::parse($xml);
        $out = [];
        foreach ($sst->children(self::MAIN_NS)->si as $si) {
            $out[] = self::textOf($si);
        }

        return $out;
    }

    /** The path of the lowest-numbered worksheet part (usually `sheet1.xml`). */
    private static function firstSheetName(ZipArchive $zip): string
    {
        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $m) === 1) {
                $sheets[(int) $m[1]] = $name;
            }
        }

        if ($sheets === []) {
            throw new RuntimeException('The spreadsheet has no worksheet.');
        }

        ksort($sheets);

        return (string) reset($sheets);
    }

    /**
     * Parse a worksheet's rows. `$shared` resolves `t="s"` cells; inline strings
     * and formula strings are read directly; anything else is a raw number.
     *
     * @param  list<string>  $shared
     * @return list<list<string>>
     */
    private static function rows(string $sheetXml, array $shared): array
    {
        $sheet = self::parse($sheetXml);
        $rows = [];

        foreach ($sheet->children(self::MAIN_NS)->sheetData->children(self::MAIN_NS)->row as $row) {
            $cells = [];
            $auto = 0;
            foreach ($row->children(self::MAIN_NS)->c as $c) {
                // Unprefixed attributes carry no namespace, so read them via
                // attributes() — $c['r'] is empty on a children()-derived node.
                $attrs = $c->attributes();
                $ref = (string) ($attrs->r ?? '');
                $type = (string) ($attrs->t ?? '');

                $col = $ref !== '' ? self::columnIndex($ref) : $auto;
                $auto = $col + 1;
                $cells[$col] = self::cellValue($c, $type, $shared);
            }

            $rows[] = self::fill($cells);
        }

        return $rows;
    }

    /**
     * Resolve one cell to its string value by type: shared string, inline string,
     * formula string, or a raw number/boolean.
     *
     * @param  list<string>  $shared
     */
    private static function cellValue(SimpleXMLElement $c, string $type, array $shared): string
    {
        return match ($type) {
            's' => $shared[(int) self::childText($c, 'v')] ?? '',
            'inlineStr' => self::textOf($c->children(self::MAIN_NS)->is),
            'str' => self::childText($c, 'v'),
            default => self::childText($c, 'v'), // n (number), b (boolean), date serials
        };
    }

    /**
     * Concatenated text of a string node's runs — a direct `<t>` and/or several
     * `<r><t>` runs (rich text). Navigates by namespace child rather than xpath,
     * which misbehaves on children()-derived nodes.
     */
    private static function textOf(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }

        $text = '';
        foreach ($node->children(self::MAIN_NS)->t as $t) {
            $text .= (string) $t;
        }
        foreach ($node->children(self::MAIN_NS)->r as $run) {
            foreach ($run->children(self::MAIN_NS)->t as $t) {
                $text .= (string) $t;
            }
        }

        return $text;
    }

    private static function childText(SimpleXMLElement $c, string $child): string
    {
        $node = $c->children(self::MAIN_NS)->{$child};

        return $node === null ? '' : (string) $node;
    }

    /** "AB12" → 27 (0-based column index from the letter prefix of a cell ref). */
    private static function columnIndex(string $ref): int
    {
        preg_match('/^[A-Za-z]+/', $ref, $m);
        $letters = strtoupper($m[0] ?? 'A');

        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /**
     * Turn a sparse [colIndex => value] map into a dense 0..max list, filling gaps
     * with empty strings so column positions stay stable.
     *
     * @param  array<int, string>  $cells
     * @return list<string>
     */
    private static function fill(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $max = max(array_keys($cells));
        $out = [];
        for ($i = 0; $i <= $max; $i++) {
            $out[] = $cells[$i] ?? '';
        }

        return $out;
    }

    private static function parse(string $xml): SimpleXMLElement
    {
        $parsed = simplexml_load_string($xml);
        if ($parsed === false) {
            throw new RuntimeException('The spreadsheet is malformed.');
        }

        return $parsed;
    }
}
