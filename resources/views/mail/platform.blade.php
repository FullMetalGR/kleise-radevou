{{--
    The platform's branded mail: one column, the wordmark as styled text, the brand's colours and
    type inline as the tokens file spells them, no remote image and no tracking pixel. Every
    string is handed in by BrandedMail, read from mail.php in the tenant's language, so this view
    reads no translator and no request; each paragraph is text, escaped here, or a link, printed
    as an anchor to its own address. Tables because mail clients read little else; styles inline
    because most read no stylesheet.
--}}
<!doctype html>
<html lang="{{ $language }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:{{ $tokens['paper'] }};">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:{{ $tokens['paper'] }};">
<tr>
<td align="center" style="padding:32px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:{{ $tokens['surface'] }};border:1px solid {{ $tokens['line'] }};border-top:4px solid {{ $tokens['primary'] }};border-radius:8px;">
<tr>
<td style="padding:24px 32px 8px 32px;">
<span style="font-family:{{ $tokens['font'] }};font-size:20px;font-weight:700;letter-spacing:-0.01em;line-height:1;color:{{ $tokens['primary'] }};">{{ $name }}</span>
</td>
</tr>
<tr>
<td style="padding:8px 32px 24px 32px;font-family:{{ $tokens['font'] }};font-size:16px;line-height:1.5;color:{{ $tokens['ink'] }};">
@foreach ($paragraphs as $paragraph)
@if ($paragraph['href'] !== null)
<p style="margin:0 0 16px 0;word-break:break-all;"><a href="{{ $paragraph['href'] }}" style="color:{{ $tokens['primary'] }};text-decoration:underline;">{{ $paragraph['text'] }}</a></p>
@else
<p style="margin:0 0 16px 0;">{{ $paragraph['text'] }}</p>
@endif
@endforeach
</td>
</tr>
<tr>
<td style="padding:16px 32px;border-top:1px solid {{ $tokens['line'] }};font-family:{{ $tokens['font'] }};font-size:13px;line-height:1.5;color:{{ $tokens['muted'] }};">
<p style="margin:0;">{{ $reason }}</p>
<p style="margin:8px 0 0 0;">{{ $sentBy }}</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
