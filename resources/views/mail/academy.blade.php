{{-- R12: one branded template for every Academy email (design N-Panel §2). --}}
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $heading }}</title></head>
<body style="margin:0;padding:24px 12px;background:#E9EBEF;font-family:'IBM Plex Sans',Arial,sans-serif;color:#12141B">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;margin:0 auto;background:#FFFFFF;border-radius:16px">
    <tr><td style="padding:28px 28px 32px">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>
        <td style="width:26px;height:26px;background:#3D2FD6;border-radius:8px"></td>
        <td style="padding-left:8px;font-family:Sora,Arial,sans-serif;font-weight:700">academy</td>
      </tr></table>
      <h2 style="margin:16px 0 0;font-family:Sora,Arial,sans-serif;font-size:22px;font-weight:700;line-height:1.3">{{ $heading }}</h2>
      <div style="margin-top:16px;background:#F5F6F8;border-radius:12px;padding:14px 16px">
        <b>{{ $cardTitle }}</b>
        @if (filled($cardText))
          <div style="font-size:14px;color:#3A404C;margin-top:4px">{{ $cardText }}</div>
        @endif
      </div>
      <a href="{{ $url }}" style="display:inline-block;margin-top:16px;background:#3D2FD6;color:#FFFFFF;border-radius:12px;padding:12px 22px;font-weight:600;text-decoration:none">{{ $button }}</a>
      <p style="margin:16px 0 0;font-size:12px;color:#5A6170;line-height:1.5">You get this because {{ $company }} uses Academy. Change which emails you get in Settings.</p>
    </td></tr>
  </table>
</body>
</html>
