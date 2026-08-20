<?php

declare(strict_types=1);

namespace App\Domain\Competition\Support;

use App\Support\PdfWriter;
use Illuminate\Support\Facades\DB;

/**
 * Builds the printable attendance register (invigilation sheet) for one venue,
 * as mPDF-friendly body HTML the caller renders with {@see PdfWriter}.
 *
 * One section per selected difficulty level that has students at the venue
 * (levels with none are skipped): a level/venue heading, the candidate roster
 * (Number | Candidate number | Name & Surname | blank Signature, ordered by
 * competitor number), a page break, then the certification block a proctor fills
 * in by hand — present/absent tallies, the invigilator declaration, and signature
 * boxes. This is a blank register for exam day, NOT a data export (that is the
 * .xlsx {@see RegistrationExporter}). Scoped by venue + difficulty level only —
 * no exam round is involved.
 */
final class AttendanceReport
{
    /**
     * Body HTML for the register, or '' when no selected level has any students
     * at the venue (the caller turns that into a "nothing to print" response).
     *
     * @param  list<int>  $levelIds  difficulty-level ids, in the order to print
     */
    public static function html(int $schoolId, array $levelIds): string
    {
        $levelIds = array_values(array_unique(array_map('intval', $levelIds)));
        if ($levelIds === []) {
            return '';
        }

        $venue = DB::table('schools as s')
            ->leftJoin('countries as c', 's.country_id', '=', 'c.id')
            ->where('s.id', $schoolId)
            ->first(['s.name as venue', 's.city as city', 'c.name as country']);
        if ($venue === null) {
            return '';
        }

        $levelNames = DB::table('difficulty_levels')->whereIn('id', $levelIds)->pluck('name', 'id');

        $students = DB::table('registrations as r')
            ->where('r.school_id', $schoolId)
            ->whereIn('r.difficulty_level_id', $levelIds)
            ->orderBy('r.competitor_number')
            ->get(['r.difficulty_level_id as level_id', 'r.competitor_number', 'r.name']);

        // Group by level so each section lists only that level's candidates.
        $byLevel = [];
        foreach ($students as $s) {
            $byLevel[(int) $s->level_id][] = $s;
        }

        $sections = [];
        foreach ($levelIds as $levelId) {
            $rows = $byLevel[$levelId] ?? [];
            if ($rows === []) {
                continue; // a level with no students here prints nothing
            }
            $sections[] = self::section((string) ($levelNames[$levelId] ?? ''), $venue, $rows);
        }

        // Each level starts on a fresh page.
        return implode('<div style="page-break-after: always;"></div>', $sections);
    }

    /**
     * One level's section: heading + candidate roster, a page break, then the
     * proctor certification block.
     *
     * @param  list<object>  $rows
     */
    private static function section(string $levelName, object $venue, array $rows): string
    {
        $heading = '<div style="text-align:center;margin-bottom:8px;">'
            .'<h2 style="font-size:15pt;margin:0 0 4px;">Attendance Register</h2>'
            .'<h3 style="font-size:12pt;margin:0 0 4px;">'.e($levelName).'</h3>'
            .'<h3 style="font-size:11pt;font-weight:normal;margin:0;">Venue — '.e(self::venueLine($venue)).'</h3>'
            .'</div>';

        $cell = 'border:0.6pt solid #d1d5db;padding:5px;';
        $body = '';
        foreach ($rows as $i => $s) {
            $bg = $i % 2 === 1 ? 'background:#f9fafb;' : '';
            $body .= '<tr style="'.$bg.'">'
                .'<td style="'.$cell.'text-align:center;width:10%;">'.($i + 1).'</td>'
                .'<td style="'.$cell.'text-align:center;width:25%;">'.e((string) $s->competitor_number).'</td>'
                .'<td style="'.$cell.'padding:5px 10px;">'.e((string) $s->name).'</td>'
                .'<td style="'.$cell.'width:30%;">&nbsp;</td>'
                .'</tr>';
        }

        $table = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:9pt;">'
            .'<thead><tr style="background:#f3f4f6;">'
            .'<th style="'.$cell.'">Number</th>'
            .'<th style="'.$cell.'">Candidate number</th>'
            .'<th style="'.$cell.'">Name &amp; Surname</th>'
            .'<th style="'.$cell.'">Signature</th>'
            .'</tr></thead><tbody>'.$body.'</tbody></table>';

        return $heading.$table.'<div style="page-break-after: always;"></div>'.self::certification();
    }

    /** The proctor certification block: tallies, declaration, signature boxes. */
    private static function certification(): string
    {
        $cell = 'border:0.6pt solid #d1d5db;padding:6px 8px;';

        $tally = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:9pt;">'
            .'<thead><tr>'
            .'<th style="'.$cell.'text-align:left;width:20%;">Total Candidates</th>'
            .'<th style="'.$cell.'width:10%;"></th>'
            .'<th style="'.$cell.'text-align:left;" colspan="2">Tests Took Place in:</th>'
            .'</tr></thead><tbody>'
            .'<tr><td style="'.$cell.'">Number Present</td><td style="'.$cell.'"></td>'
            .'<td style="'.$cell.'text-align:center;width:35%;">Reading (Start – Finish)</td>'
            .'<td style="'.$cell.'text-align:center;width:35%;">Use (Start – Finish)</td></tr>'
            .'<tr><td style="'.$cell.'">Number Absent</td><td style="'.$cell.'"></td>'
            .'<td style="'.$cell.'"></td><td style="'.$cell.'"></td></tr>'
            .'</tbody></table>';

        $declaration = '<p style="margin:12px 0 4px;">I/We the undersigned invigilator(s) hereby certify:</p>'
            .'<ol style="margin:0 0 8px;padding-left:18px;">'
            .'<li>That I/we was/were present during the whole period of the examination as indicated below.</li>'
            .'<li>That the number of candidates who presented themselves was as indicated above.</li>'
            .'<li>That the envelope(s) containing the contest materials was/were opened by me/us at ________ am/pm.</li>'
            .'<li>That the contest materials were worked in my/our presence and were collected at the end of the examination.</li>'
            .'<li>That examination regulations have been strictly complied with.</li>'
            .'<li>That any suspected malpractice during the examination has been recorded in the box below.<br><br>Date:</li>'
            .'</ol>';

        $signatures = '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:9pt;">'
            .'<tr><td style="'.$cell.'">Name of Invigilator (PRINT)</td><td style="'.$cell.'">Signature</td><td style="'.$cell.'">Suspected Malpractice Box*</td></tr>'
            .'<tr><td style="'.$cell.'height:26px;"></td><td style="'.$cell.'"></td><td style="'.$cell.'" rowspan="3"></td></tr>'
            .'<tr><td style="'.$cell.'height:26px;"></td><td style="'.$cell.'"></td></tr>'
            .'<tr><td style="'.$cell.'height:26px;"></td><td style="'.$cell.'"></td></tr>'
            .'</table>';

        $footer = '<p style="margin:8px 0 0;font-size:8pt;color:#6b7280;">*Please note: any irregularities, disturbances, use of unauthorized materials, attempts of cheating, etc.</p>'
            .'<p style="margin:16px 0 0;">Name of Coordinator (PRINT): _______________________________________</p>'
            .'<p style="margin:16px 0 0;">Signature of Coordinator: _______________________________________</p>';

        return $tally.$declaration.$signatures.$footer;
    }

    /** "Venue name, City, Country" from the non-empty parts. */
    private static function venueLine(object $venue): string
    {
        return implode(', ', array_filter([
            (string) ($venue->venue ?? ''),
            (string) ($venue->city ?? ''),
            (string) ($venue->country ?? ''),
        ], fn (string $p) => $p !== ''));
    }
}
