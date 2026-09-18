{{--
    The foot of every mail (owner, 2026-09-18): a brand rule dividing it from
    the words, the administration's own footer paragraph, and under it the
    copyright line the website carries.

    🔴 The same two values the public footer draws, read from the same layout
    block (ADR-0045). Not a copy: a second wording kept in the code would be
    right on the day it was written and wrong from the first time somebody
    edited the footer on the website.

    🪤 `{year}` is substituted when the mail is drawn, never stored — see
    MailBranding::copyright().
--}}
@php($brand = app(App\Domain\Communication\Support\MailBranding::class))
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr><td style="height: 2px; line-height: 2px; font-size: 0; background-color: {{ $brand->rule() }};">&nbsp;</td></tr>
<tr>
<td class="content-cell" align="left" style="padding: 18px 0 0;">
@if ($brand->footerText())
{{-- Admin-authored markup, the same trust the CMS gives its own pages. --}}
<div style="font-size: 13px; line-height: 1.6; color: #6b7280;">{!! $brand->footerText() !!}</div>
@endif
@if ($brand->copyright())
<p style="margin: 12px 0 0; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #9ca3af;">
{{ $brand->copyright() }}
</p>
@endif
{{-- Whatever the Mailable put in the footer slot still goes out, under the
     branding. Empty for every mail today, and the reason a package upgrade
     that starts using it will not silently lose it. --}}
@if (trim($slot) !== '')
<div style="margin-top: 10px; font-size: 11px; color: #9ca3af;">{{ $slot }}</div>
@endif
</td>
</tr>
</table>
</td>
</tr>
