{{--
    The decline mail (ADR-0053). Deliberately carries no reason: `decline_reason`
    is a note between reviewers, and forwarding it would publish an internal
    judgement about a named school. The applicant gets the decision and a way to
    ask about it.
--}}
<x-mail::message>
# About your registration

Hello {{ $name }},

Your registration as a school coordinator for {{ $siteName }} has not been approved, so no account has been opened.

If you think this is a mistake — or if the venue approval you attached was the wrong document — write to {{ $contactAddress }} and we will look at it again.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
