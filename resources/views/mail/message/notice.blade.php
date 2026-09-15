{{--
    An administrator's message to coordinators (2026-09-15).

    Two shapes, one for each way the body was written:

    - `html` is what the rich editor produced. It is admin-authored markup, the
      same trust the CMS gives its own pages, so it is printed as markup.
    - `body` is the plain text a notification carries. It is escaped, and its
      single newlines were turned into Markdown hard breaks on the way in, so
      the mail reads the way the box looked.

    The link is the sign-in page rather than a deep link into the message: there
    is nothing to act on in a mail, and the coordinator's own screen is where
    the numbers and the exam password live.
--}}
<x-mail::message>
# {{ $message->subject }}

Hello {{ $name }},

@if (filled($html))
<div>{!! $html !!}</div>
@else
{{ $body }}
@endif

<x-mail::button :url="$loginUrl">Open {{ $siteName }}</x-mail::button>

Thanks,<br>
{{ $siteName }}
</x-mail::message>
