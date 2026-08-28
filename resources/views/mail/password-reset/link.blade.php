{{--
    The password recovery mail (ADR-0063). Goes to the account holder and to
    nobody else, whether they asked for it themselves or an administrator sent it
    for them — the two are the same mail on purpose, so that there is one flow to
    trust rather than two, and so an administrator never learns the password.

    It names the address the link belongs to. A coordinator with a school inbox
    and a personal one needs to know which of them the account is under, and
    being told costs nothing: whoever is reading this mail already has it.
--}}
<x-mail::message>
# Set a new password

Hello {{ $name }},

Somebody asked to set a new password for **{{ $email }}** on {{ $siteName }}. Use the button below and choose one.

<x-mail::button :url="$resetUrl">Set a new password</x-mail::button>

The link works once and stops working in {{ $minutes }} minutes. If you did not ask for it, you do not have to do anything — your password has not changed.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
