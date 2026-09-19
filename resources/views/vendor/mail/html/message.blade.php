{{--
    Laravel's own, with both slots emptied (owner, 2026-09-18).

    🔴 The header and the footer now read the administration's branding
    themselves ({@see App\Domain\Communication\Support\MailBranding}), so the
    defaults this file used to hand them are not merely unused — they would be
    DRAWN. The footer's default is a copyright line written in the framework's
    words, and under a footer that already carries the administration's own it
    would print the same sentence twice, differently.

    🪤 Kept as a copy of the package file rather than deleted, because deleting
    it restores those defaults instead of removing them.

    🔴 The greeting and the sign-off live HERE, not in each letter (ADR-0132).
    All four said `Hello {name},` and `Thanks, {site}` in their own words, which
    is four places to edit and four chances for the next one to say something
    else. A Mailable now only says WHO it is greeting; what the greeting is, is
    the administration's. Pass `:greet="$name"` — `null` leaves it out, for a
    letter with nobody to address.
--}}
@props(['greet' => null])
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')"></x-mail::header>
</x-slot:header>

{{-- Body --}}
@php($brand = app(App\Domain\Communication\Support\MailBranding::class))
@if ($greet !== null && $brand->greeting($greet))
{{ $brand->greeting($greet) }}
@endif

{!! $slot !!}

@if ($brand->signOff())
{!! nl2br(e($brand->signOff())) !!}
@endif

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer></x-mail::footer>
</x-slot:footer>
</x-mail::layout>
