{{--
    The approval mail (ADR-0053). Goes to the APPLICANT — the legacy app sent its
    one mail to the organisation's own inbox instead, so the coordinator was never
    told anything. No password and no acting link: the account already exists, and
    the password is the one they chose when they registered.
--}}
<x-mail::message>
# Your account is open

Hello {{ $name }},

An administrator has approved your registration as a school coordinator for {{ $siteName }}. You can sign in now, with **{{ $email }}** and the password you chose when you registered.

<x-mail::button :url="$loginUrl">Sign in</x-mail::button>

If you have forgotten the password, use “Forgot password?” on the sign-in screen.

Thanks,<br>
{{ $siteName }}
</x-mail::message>
