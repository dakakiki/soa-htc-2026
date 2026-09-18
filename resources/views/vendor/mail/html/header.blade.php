{{--
    The masthead of every mail this application sends (owner, 2026-09-18):
    the logo and the title on the LEFT, and a brand rule under them dividing
    the header from the words.

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
<td align="left" style="padding: 0 0 18px;">
@if ($brand->logoUrl())
{{-- 🪤 Sized in the attribute as well as the style: a client that drops the
     style attribute still has to be told how big this is, or it draws the
     upload at whatever pixels it happens to be. --}}
<img src="{{ $brand->logoUrl() }}" alt="{{ $brand->title() }}" height="34"
    style="height: 34px; max-height: 34px; width: auto; border: 0; display: block; margin: 0 0 10px;">
@endif
<span style="font-size: 17px; font-weight: 600; letter-spacing: -0.01em; color: {{ $brand->rule() }};">{{ $brand->title() }}</span>
</td>
</tr>
{{-- The rule. A bordered row rather than an <hr>, which mail clients each
     draw in their own colour and thickness. --}}
<tr><td style="height: 2px; line-height: 2px; font-size: 0; background-color: {{ $brand->rule() }};">&nbsp;</td></tr>
</table>
</td>
</tr>
