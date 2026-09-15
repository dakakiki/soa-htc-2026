{{--
    An administrator's message to coordinators (2026-09-15).

    🪤 `{{ }}` and nothing else: the subject and the body are typed by a person
    in the administration, and this mail is the one place that text leaves the
    system. Escaped here, it cannot carry markup into anybody's inbox.

    The link is the sign-in page rather than a deep link into the message: there
    is nothing to act on in a mail, and the coordinator's own screen is where
    the numbers and the exam password live.
--}}
<x-mail::message>
# {{ $message->subject }}

Hello {{ $name }},

{{ $body }}

<x-mail::button :url="$loginUrl">Open {{ $siteName }}</x-mail::button>

Thanks,<br>
{{ $siteName }}
</x-mail::message>
