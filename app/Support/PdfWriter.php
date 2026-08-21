<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Organization\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;

/**
 * Reusable branded PDF renderer (HTML → PDF via mPDF). Every document carries the
 * SOA HTC header (logo + name) and a page-number footer, so reports, certificates
 * (Faza 7) and future multi-page documents share one consistent look. Returns the
 * PDF as a binary string for a download response (mirror of {@see XlsxWriter}).
 */
final class PdfWriter
{
    /**
     * @param  string  $bodyHtml  the document body (HTML with inline styles; mPDF
     *                            supports tables + basic CSS, not flex/color-mix)
     * @param  string  $title  shown in the header opposite the brand name
     * @param  'P'|'L'  $orientation  portrait or landscape
     */
    public static function toString(string $bodyHtml, string $title = '', string $orientation = 'P'): string
    {
        $mpdf = self::make($orientation);
        $mpdf->SetHTMLHeader(self::header($title));
        $mpdf->SetHTMLFooter(self::footer());
        $mpdf->WriteHTML($bodyHtml);

        return $mpdf->Output('', 'S');
    }

    /**
     * Build a document from many page fragments, written one at a time (each on
     * its own page). For large runs — thousands of one-page certificates in a
     * single PDF — this keeps peak memory far below parsing one giant HTML string,
     * and lifts the request's memory/time limits so the whole venue renders at once
     * (no chunking).
     *
     * @param  iterable<string>  $pages
     */
    public static function fromPages(iterable $pages, string $title = '', string $orientation = 'P', string $styleHead = '', bool $plain = false): string
    {
        self::liftLimits();

        $mpdf = self::make($orientation, $plain);
        // "plain" pages (e.g. certificates) own the whole page, so skip the running
        // brand header + page-number footer and use small uniform margins.
        if (! $plain) {
            $mpdf->SetHTMLHeader(self::header($title));
            $mpdf->SetHTMLFooter(self::footer());
        }

        // One WriteHTML for the whole run: mPDF re-merges the stylesheet on every
        // WriteHTML call, so writing page-by-page is quadratic and unusably slow for
        // thousands of pages. A single call with an optional shared <style> head (so
        // per-page markup carries no repeated inline CSS to re-parse) stays linear.
        $html = $styleHead;
        $first = true;
        foreach ($pages as $page) {
            $html .= ($first ? '' : '<pagebreak />').(string) $page;
            $first = false;
        }

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }

    private static function make(string $orientation, bool $plain = false): Mpdf
    {
        // mPDF caches fonts to a temp dir; keep it under storage (always writable,
        // unlike a CI-deployed read-only vendor/).
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $orientation === 'L' ? 'A4-L' : 'A4',
            'margin_top' => $plain ? 12 : 34,
            'margin_bottom' => $plain ? 12 : 18,
            'margin_left' => 12,
            'margin_right' => 12,
            // DejaVu ships with mPDF and covers Cyrillic + Latin diacritics (ČĆŽŠĐ).
            'default_font' => 'dejavusans',
            'default_font_size' => 9,
            'tempDir' => $tmp,
        ]);
    }

    /** Give a large render room to finish: a generous time cap and at least 1 GB of memory. */
    private static function liftLimits(): void
    {
        @set_time_limit(300);

        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1') {
            return; // already unlimited
        }
        $bytes = self::toBytes($limit);
        if ($bytes > 0 && $bytes < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower($value[strlen($value) - 1])) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    /** Brand header: logo (when set) + "SOA HTC" name on the left, title on the right. */
    private static function header(string $title): string
    {
        $logo = self::logoTag();
        $safeTitle = e($title);

        return <<<HTML
            <table width="100%" style="border-bottom: 0.6pt solid #e5e7eb;">
                <tr>
                    <td style="vertical-align: middle;">{$logo}<span style="font-size: 13pt; font-weight: bold; color: #111827;">SOA HTC</span></td>
                    <td style="text-align: right; vertical-align: middle; font-size: 9pt; color: #6b7280;">{$safeTitle}</td>
                </tr>
            </table>
            HTML;
    }

    private static function footer(): string
    {
        return '<table width="100%" style="border-top: 0.6pt solid #e5e7eb; font-size: 8pt; color: #9ca3af;">'
            .'<tr><td>SOA HTC</td><td style="text-align: right;">{PAGENO} / {nbpg}</td></tr></table>';
    }

    /** The brand logo as an inline data-URI <img>, or empty when none is configured. */
    private static function logoTag(): string
    {
        $path = Setting::current()->logo_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            default => 'image/png',
        };
        $data = base64_encode(Storage::disk('public')->get($path));

        return '<img src="data:'.$mime.';base64,'.$data.'" style="height: 20px; margin-right: 6px; vertical-align: middle;" /> ';
    }
}
