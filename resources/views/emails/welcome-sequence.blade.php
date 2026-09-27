<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ config('app.name') }}</title>
<style>
    @media only screen and (max-width: 600px) {
        .email-container { width: 100% !important; }
        .email-padding { padding: 24px 20px !important; }
        .email-header-padding { padding: 28px 20px !important; }
        .email-button { width: 100% !important; }
        .email-copy { font-size: 16px !important; }
    }
</style>
</head>
<body style="margin: 0; padding: 0; background-color: #F7EAE0;">
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preview }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #F7EAE0; padding: 32px 0;">
<tr>
<td align="center">

<table class="email-container" role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width: 600px; max-width: 600px; border-collapse: collapse;">

<!-- Brand header -->
<tr>
<td style="background: linear-gradient(135deg, #1D4533 0%, #2F6650 100%); border-radius: 16px 16px 0 0; text-align: center;" class="email-header-padding">
    <div style="padding: 28px 32px; font-family: 'Fraunces', Georgia, 'Times New Roman', serif;">
        <span style="font-size: 30px; font-weight: 700; color: #F7EAE0; letter-spacing: 0.5px;">Batayan<span style="color: #F9D2BA;">.ai</span></span>
        <div style="font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 11px; font-weight: 600; color: #F9D2BA; letter-spacing: 3px; text-transform: uppercase; margin-top: 6px;">Legal Drafting, Reimagined</div>
    </div>
</td>
</tr>

<tr>
<td style="background-color: #F9D2BA; height: 6px; font-size: 0; line-height: 0;">&nbsp;</td>
</tr>

<!-- Body card -->
<tr>
<td style="background-color: #FCEADE; border-left: 1px solid #E5BA9A; border-right: 1px solid #E5BA9A;" class="email-padding">
<div style="padding: 40px 40px 8px 40px;">

    <h1 style="margin: 0 0 16px 0; font-family: 'Fraunces', Georgia, 'Times New Roman', serif; font-size: 26px; font-weight: 700; color: #1D4533; line-height: 1.3;">{{ $heading }}</h1>

    <p class="email-copy" style="margin: 0 0 16px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">Hi {{ $firstName }},</p>

    @foreach ($paragraphs as $paragraph)
        <p class="email-copy" style="margin: 0 0 16px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">{{ $paragraph }}</p>
    @endforeach

    @if (count($bullets) > 0)
        <ul class="email-copy" style="margin: 0 0 16px 0; padding-left: 22px; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">
            @foreach ($bullets as $bullet)
                <li style="margin: 0 0 6px 0;">{{ $bullet }}</li>
            @endforeach
        </ul>
    @endif

    @if ($quote)
        <p class="email-copy" style="margin: 0 0 16px 0; padding: 12px 16px; border-left: 3px solid #F9D2BA; background-color: #F7EAE0; font-style: italic; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">"{{ $quote }}"</p>
    @endif

    @if ($after)
        <p class="email-copy" style="margin: 0 0 24px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">{{ $after }}</p>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 8px 0 24px 0;">
        <tr>
            <td class="email-button" align="center" style="background-color: #1D4533; border-radius: 10px; box-shadow: 0 4px 12px rgba(29, 69, 51, 0.25);">
                <a href="{{ $ctaUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 16px 40px; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; font-weight: 700; color: #F7EAE0; text-decoration: none; letter-spacing: 0.3px;">{{ $cta_label }}</a>
            </td>
        </tr>
    </table>

    @if ($postscript)
        <p class="email-copy" style="margin: 0 0 16px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">{{ $postscript }}</p>
    @endif

    @if ($secondaryUrl)
        <p class="email-copy" style="margin: 0 0 16px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;"><a href="{{ $secondaryUrl }}" style="color: #1D4533; font-weight: 600;">{{ $secondary_label }} &rarr;</a></p>
    @endif

    <p class="email-copy" style="margin: 0 0 32px 0; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 16px; line-height: 1.65; color: #5E3122;">{{ $signature }}</p>

</div>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background-color: #F2E2D3; border-radius: 0 0 16px 16px; border-left: 1px solid #E5BA9A; border-right: 1px solid #E5BA9A; border-bottom: 1px solid #E5BA9A; text-align: center;">
    <div style="padding: 24px 32px; font-family: 'Inter', -apple-system, 'Segoe UI', sans-serif; font-size: 12px; line-height: 1.7; color: #8A6A58;">
        <div style="font-weight: 600; color: #5E3122; margin-bottom: 4px;">Batayan.ai</div>
        <div>© {{ date('Y') }} Batayan.ai. All rights reserved.</div>
        <div style="margin-top: 12px;">
            <a href="{{ rtrim((string) config('app.frontend_url'), '/') }}" style="color: #1D4533; text-decoration: none; font-weight: 600;">Visit our website</a>
        </div>
        <div style="margin-top: 8px;">
            You're receiving tips because you started a Batayan trial.
            <a href="{{ $unsubscribeUrl }}" style="color: #8A6A58; text-decoration: underline;">Unsubscribe</a>
        </div>
    </div>
</td>
</tr>

</table>
</td>
</tr>
</table>

</body>
</html>
