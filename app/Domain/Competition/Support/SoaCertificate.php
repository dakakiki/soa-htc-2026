<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Domain\Organization\Models\Setting;
use App\Domain\Organization\Support\SeasonContext;
use App\Support\PdfWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the "SOA Cert" participation certificates for one venue as mPDF-friendly
 * body HTML the caller renders with {@see PdfWriter}. One full page per student in
 * the scope (Country + Venue + one or more difficulty levels), ordered by
 * competitor number. mPDF renders ~0.3s per page, so the controller downloads the
 * certificates in fixed-size chunks (like the legacy app) rather than one huge PDF.
 *
 * The content is admin-editable in Settings: a rich-text body whose [placeholders]
 * ([name], [category], [venue], [school], [city], [country], [round], [points]) are
 * substituted per student, plus an uploaded header logo, signature image + caption,
 * and QR code. Everything falls back to a built-in default when unset. The two
 * printed marks come from the results layer (`registration_results`, Layer B =
 * published scores): Preliminary → Reading + Use of English; National → Reading +
 * Writing. Students with no published score show 0.
 */
final class SoaCertificate
{
    /**
     * Round → its canonical exam-round name, display title, and the two test
     * types whose scores print on the certificate (legacy fixed pairs).
     */
    private const ROUNDS = [
        'preliminary' => ['round' => 'Preliminary round', 'title' => 'Preliminary round', 'marks' => ['Reading', 'Use of English']],
        'national' => ['round' => 'National round', 'title' => 'National round', 'marks' => ['Reading', 'Writing']],
    ];

    /** The placeholders admins may use in the body, with a short explanation (drives the editor legend). */
    public const PLACEHOLDERS = [
        '[name]' => "The competitor's full name",
        '[category]' => 'Difficulty category (e.g. LITTLE HIPPO)',
        '[round]' => 'The competition round (Preliminary Round / National Round)',
        '[edition]' => 'The contest edition as a superscript ordinal from the active season (e.g. 14th)',
        '[venue]' => 'Venue name (where the competitor sat)',
        '[school]' => 'Home school if entered, otherwise the venue',
        '[city]' => "Venue's city",
        '[country]' => "Venue's country",
        '[points]' => "The round's marks (e.g. Reading: 28, Use of English: 28.5)",
    ];

    /**
     * Built-in body used when Settings has no custom certificate body. Uses <br>
     * for line breaks and the .name class for the heading — mPDF ignores <p>/<h2>
     * margins inside a table cell, so spacing is driven by explicit breaks.
     */
    private const DEFAULT_BODY =
        'This is to confirm that<br><br><br>'
        .'<span class="name">[name]</span><br><br><br>'
        .'participated in the [round] of<br>'
        .'<strong>[edition] HIPPO ENGLISH LANGUAGE CONTEST</strong><br>'
        .'in category [category]<br><br>'
        .'Venue: [venue]<br>'
        .'School: [school]<br>'
        .'City: [city]<br><br>'
        .'with the following points<br><br>'
        .'[points]';

    /** The rounds a certificate can be issued for (drives the modal dropdown + validation). */
    public static function rounds(): array
    {
        return array_keys(self::ROUNDS);
    }

    /** The built-in body, shown in the Settings editor when no custom body is saved. */
    public static function defaultBody(): string
    {
        return self::DEFAULT_BODY;
    }

    /** Number of students in the scope — the total the chunk plan divides into parts (round-independent). */
    public static function count(int $schoolId, array $levelIds): int
    {
        $levelIds = array_values(array_unique(array_map('intval', $levelIds)));
        if ($levelIds === []) {
            return 0;
        }

        return DB::table('registrations')
            ->where('school_id', $schoolId)
            ->whereIn('difficulty_level_id', $levelIds)
            ->count();
    }

    /**
     * One certificate page (HTML) per student in the scope, ordered by competitor
     * number — the list the caller renders with {@see PdfWriter::fromPages()},
     * chunking into parts when the venue is large. Empty when no student matches.
     *
     * @param  list<int>  $levelIds  difficulty-level ids
     * @param  string  $round  one of {@see rounds()}
     * @return list<string>
     */
    public static function pages(int $schoolId, array $levelIds, string $round): array
    {
        $config = self::ROUNDS[$round] ?? null;
        if ($config === null) {
            return [];
        }

        $levelIds = array_values(array_unique(array_map('intval', $levelIds)));
        if ($levelIds === []) {
            return [];
        }

        $roundId = (int) DB::table('exam_rounds')->where('name', $config['round'])->value('id');

        // Test-type ids for the two printed marks, kept in the display order.
        $typeIdByName = DB::table('test_types')->whereIn('name', $config['marks'])->pluck('id', 'name');
        $markTypes = [];
        foreach ($config['marks'] as $name) {
            if (isset($typeIdByName[$name])) {
                $markTypes[$name] = (int) $typeIdByName[$name];
            }
        }

        $students = DB::table('registrations as r')
            ->leftJoin('schools as s', 'r.school_id', '=', 's.id')
            ->leftJoin('countries as c', 'r.country_id', '=', 'c.id')
            ->leftJoin('difficulty_levels as dl', 'r.difficulty_level_id', '=', 'dl.id')
            ->where('r.school_id', $schoolId)
            ->whereIn('r.difficulty_level_id', $levelIds)
            ->orderBy('r.competitor_number')
            ->get([
                'r.id', 'r.competitor_number', 'r.name', 'r.school_external',
                'dl.name as level', 's.name as venue', 's.city as city', 'c.name as country',
            ]);

        if ($students->isEmpty()) {
            return [];
        }

        // marks[registration_id][test_type_id] = summed published score for this round.
        $marks = [];
        if ($markTypes !== []) {
            foreach (
                DB::table('registration_results')
                    ->whereIn('registration_id', $students->pluck('id'))
                    ->where('exam_round_id', $roundId)
                    ->whereIn('test_type_id', array_values($markTypes))
                    ->get(['registration_id', 'test_type_id', 'score']) as $row
            ) {
                $rid = (int) $row->registration_id;
                $tt = (int) $row->test_type_id;
                $marks[$rid][$tt] = ($marks[$rid][$tt] ?? 0.0) + (float) $row->score;
            }
        }

        // Admin-editable content + brand assets, resolved once (mPDF caches images by src).
        $setting = Setting::current();
        $body = trim((string) ($setting->cert_body ?? '')) !== '' ? (string) $setting->cert_body : self::DEFAULT_BODY;

        // [edition] is season-wide (the same contest number for everyone), so resolve
        // it once from the active season and bake it into the template before the
        // per-student substitution — mirrors the legacy $round_number. It renders as
        // an ordinal with a superscript suffix (14th, 21st), the suffix computed from
        // the number rather than hard-coded.
        $roundNumber = SeasonContext::active()?->round_number;
        $edition = $roundNumber !== null
            ? $roundNumber.'<sup>'.self::ordinalSuffix((int) $roundNumber).'</sup>'
            : '';
        $body = str_replace('[edition]', $edition, $body);
        $chrome = [
            'headerTitle' => (string) ($setting->cert_header_title ?? ''),
            'logo' => self::imgData($setting->cert_logo_path),
            'signature' => self::imgData($setting->cert_signature_path),
            'qr' => self::imgData($setting->cert_qr_path),
            'signatureText' => (string) ($setting->cert_signature_text ?? ''),
        ];

        $pages = [];
        foreach ($students as $student) {
            $pages[] = self::certificate($student, $config['title'], $markTypes, $marks[(int) $student->id] ?? [], $body, $chrome);
        }

        return $pages;
    }

    /**
     * The certificates as one HTML string (style head + pages, page-broken). Used
     * where a single blob is convenient; the PDF endpoint passes {@see pages()} and
     * {@see styleHead()} to PdfWriter::fromPages() (one WriteHTML, linear cost).
     *
     * @param  list<int>  $levelIds
     */
    public static function html(int $schoolId, array $levelIds, string $round): string
    {
        $pages = self::pages($schoolId, $levelIds, $round);

        return $pages === [] ? '' : self::styleHead().implode('<pagebreak />', $pages);
    }

    /**
     * The shared <style> block for the certificate pages. Class-based so the
     * per-page markup carries no inline CSS — mPDF then parses the stylesheet once
     * instead of re-merging inline rules for every one of thousands of pages.
     */
    public static function styleHead(): string
    {
        return '<style>'
            .'.cert{border:2pt solid #111827;}'             // thick outer frame (whole page)
            .'.orgtitle{font-size:21pt;font-weight:bold;color:#0e4d92;}'
            .'.innerbox{border:0.7pt solid #9ca3af;}'       // inner content frame
            .'.body{font-size:13pt;color:#111827;}'  // left-aligned body (line-height set inline; mPDF ignores it from a class)
            .'.name{font-size:20pt;font-weight:bold;}'      // the competitor name
            .'.sigtext{font-size:11pt;color:#111827;}'
            .'</style>';
    }

    /**
     * One student's certificate page: brand logo, the admin body with placeholders
     * substituted, then the signature + QR footer.
     *
     * @param  array<string, int>  $markTypes  label => test_type_id, in print order
     * @param  array<int, float>  $studentMarks  test_type_id => score
     * @param  array{logo:string,signature:string,qr:string,signatureText:string}  $chrome
     */
    private static function certificate(object $student, string $roundTitle, array $markTypes, array $studentMarks, string $bodyTemplate, array $chrome): string
    {
        $points = [];
        foreach ($markTypes as $label => $ttId) {
            $points[] = e($label).': '.number_format($studentMarks[$ttId] ?? 0.0, 2);
        }

        $school = ($student->school_external !== null && $student->school_external !== '')
            ? (string) $student->school_external
            : (string) $student->venue;

        $body = strtr($bodyTemplate, [
            '[name]' => e((string) $student->name),
            '[category]' => e((string) $student->level),
            '[round]' => e($roundTitle),
            '[venue]' => e((string) $student->venue),
            '[school]' => e($school),
            '[city]' => e((string) $student->city),
            '[country]' => e((string) $student->country),
            '[points]' => implode('<br>', $points),
        ]);

        // Header (legacy layout): logo on the left, org title on the right, both
        // centred within their cell and vertically middled. mPDF centres inline
        // content via a <td> text-align, not via a <div>.
        $logoCell = $chrome['logo'] !== '' ? '<img src="'.$chrome['logo'].'" style="width:150px;" />' : '';
        // The header title is edited in a rich-text field, so it may arrive wrapped in
        // block tags (<p>, <h2>…). Strip those to keep it a single inline line inside
        // the .orgtitle cell — the inline <span style="color">…</span> colour marks stay.
        $headerTitle = trim((string) preg_replace('#</?(p|h[1-6]|div|ul|ol|li)[^>]*>#i', '', (string) $chrome['headerTitle']));
        $titleCell = $headerTitle !== '' ? '<span class="orgtitle">'.$headerTitle.'</span>' : '';
        $header = '<table width="100%" cellspacing="0" cellpadding="0"><tr>'
            .'<td width="28%" style="text-align:center;vertical-align:middle;">'.$logoCell.'</td>'
            .'<td width="72%" style="text-align:center;vertical-align:middle;">'.$titleCell.'</td>'
            .'</tr></table>';

        // Footer: signature + caption (left), QR (right).
        $sig = ($chrome['signature'] !== '' ? '<img src="'.$chrome['signature'].'" style="width:170px;" /><br>' : '')
            .($chrome['signatureText'] !== '' ? '<span class="sigtext">'.nl2br(e($chrome['signatureText'])).'</span>' : '');
        $qr = $chrome['qr'] !== '' ? '<img src="'.$chrome['qr'].'" style="width:150px;" />' : '';
        $footer = '<table width="100%" cellspacing="0" cellpadding="0"><tr>'
            .'<td width="62%" style="vertical-align:bottom;">'.$sig.'</td>'
            .'<td width="38%" style="vertical-align:bottom;text-align:right;">'.$qr.'</td>'
            .'</tr></table>';

        // Inner content frame. mPDF honours height on a <td> (not on <table> or via
        // height:100%): the tall body cell stretches the frame down, the footer cell
        // sits at its bottom.
        $inner = '<table width="100%" class="innerbox" cellspacing="0" cellpadding="0">'
            .'<tr><td style="vertical-align:top;padding:8mm;height:154mm;"><div class="body" style="line-height:2.2;">'.$body.'</div></td></tr>'
            .'<tr><td style="vertical-align:bottom;padding:4mm 8mm;height:48mm;">'.$footer.'</td></tr>'
            .'</table>';

        // Outer frame fills the page: an explicit <td> height (A4 minus the 12mm plain
        // margins) reaches the bottom; the header sits on top, the inner frame below.
        return '<table width="100%" class="cert" cellspacing="0" cellpadding="0"><tr>'
            .'<td style="height:270mm;vertical-align:top;padding:6mm 8mm;">'
            .$header
            .'<div style="height:8mm;line-height:8mm;">&nbsp;</div>'
            .$inner
            .'</td></tr></table>';
    }

    /** Format a score: whole numbers without decimals, otherwise up to two places. */
    private static function mark(float $score): string
    {
        return rtrim(rtrim(number_format($score, 2, '.', ''), '0'), '.') ?: '0';
    }

    /** The English ordinal suffix for a number (1 → st, 2 → nd, 3 → rd, 11-13 → th, else by last digit). */
    private static function ordinalSuffix(int $n): string
    {
        $n = abs($n);
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return 'th';
        }

        return match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /** A public-disk image as a base64 data URI (for mPDF), or '' when absent. */
    private static function imgData(?string $path): string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return '';
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }
}
