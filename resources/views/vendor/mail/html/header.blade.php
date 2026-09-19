{{--
    The masthead of every mail this application sends (owner, 2026-09-18):
    the logo on the LEFT with the name BESIDE it, and a brand rule under them
    dividing the header from the words.

    🪤 Beside, not under: two cells in one row (2026-09-19). The logo and the
    name used to share ONE cell, which stacked them whatever the comment here
    said — and it said "beside" for a day while the mail said otherwise. The
    image keeps `display: block`; in a cell of its own that only removes the
    baseline gap under it. Both the logo and the name are now the mail's own
    settings (ADR-0132), falling back to the site's.

    🔴 Overriding Laravel's own header rather than replacing the whole mail
    template. Only the three files that differ live here; the layout, the theme
    and the button stay the package's, so an upgrade brings its fixes and this
    keeps its branding.

    🪤 Resolved here rather than passed in. A mail's header is the same for every
    mail — a coordinator's notice, a password reset, whatever comes next — and
    handing each Mailable a job of filling it in would mean the next one to be
    written is the one that forgets.

    🪤 Tables and inline styles, because that is what a mail client understands.
    Outlook draws no `flex` and strips a stylesheet it does not like; two cells
    in a row are the same layout in every client there is.
--}}
@php($brand = app(App\Domain\Communication\Support\MailBranding::class))
<tr>
<td class="header" style="padding: 26px 0 0;">
<table width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
@if ($brand->logoUrl())
{{-- 🪤 Sized in the attribute as well as the style: a client that drops the
     style attribute still has to be told how big this is, or it draws the
     upload at whatever pixels it happens to be. --}}
<td align="left" valign="middle" style="padding: 0 0 18px;">
<img src="{{ $brand->logoUrl() }}" alt="{{ $brand->headerText() }}" height="34"
    style="height: 34px; max-height: 34px; width: auto; border: 0; display: block;">
</td>
@endif
<td align="left" valign="middle" width="100%" style="padding: 0 0 18px {{ $brand->logoUrl() ? '12px' : '0' }};">
<span style="font-size: 17px; font-weight: 600; letter-spacing: -0.01em; color: {{ $brand->rule() }};">{{ $brand->headerText() }}</span>
</td>
</tr>
{{-- The rule. A bordered row rather than an <hr>, which mail clients each
     draw in their own colour and thickness.

     🪤 `colspan` follows the row above: with a logo that row has two cells, and
     a rule that spanned one of them would stop under the logo. --}}
<tr><td colspan="{{ $brand->logoUrl() ? 2 : 1 }}" style="height: 2px; line-height: 2px; font-size: 0; background-color: {{ $brand->rule() }};">&nbsp;</td></tr>
</table>
</td>
</tr>
