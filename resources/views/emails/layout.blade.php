<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
</head>

<body style="margin:0; padding:0; background-color:#F5F5F4; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F5F5F4; padding:32px 16px;">
<tr>
<td align="center">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#FFFFFF; border-radius:12px; overflow:hidden; border:1px solid #E7E5E4;">

        <!-- Header -->
        <tr>
            <td style="background-color:{{ $primaryColor }}; padding:28px 32px;" align="left">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}" alt="{{ $storeName }}" style="max-height:36px; display:block;">
                @else
                    <span style="color:#FFFFFF; font-size:18px; font-weight:600; letter-spacing:0.2px;">{{ $storeName }}</span>
                @endif
            </td>
        </tr>

        <!-- Body -->
        <tr>
            <td style="padding:36px 32px;">
                <div style="color:#1C1917; font-size:15px; line-height:1.65;">
                    {!! $bodyHtml !!}
                </div>

                @if($quoteNumber)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px; background-color:#FAFAF9; border:1px solid #E7E5E4; border-radius:8px;">
                    <tr>
                        <td style="padding:14px 18px;">
                            <span style="font-size:12px; color:#78716C; text-transform:uppercase; letter-spacing:0.4px;">Quote reference</span><br>
                            <span style="font-size:15px; font-weight:600; color:#1C1917;">{{ $quoteNumber }}</span>
                        </td>
                    </tr>
                </table>
                @endif

                @if($actionUrl && $actionLabel)
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:28px;">
                    <tr>
                        <td style="border-radius:8px; background-color:{{ $primaryColor }};">
                            <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block; padding:12px 24px; color:#FFFFFF; font-size:14px; font-weight:600; text-decoration:none; border-radius:8px;">
                                {{ $actionLabel }}
                            </a>
                        </td>
                    </tr>
                </table>
                @endif
            </td>
        </tr>

        <!-- Footer -->
        <tr>
            <td style="padding:20px 32px; background-color:#FAFAF9; border-top:1px solid #E7E5E4;">
                <p style="margin:0; font-size:12.5px; color:#A8A29E; line-height:1.6;">
                    {{ $footerText ?? ('This email was sent by ' . $storeName . ' regarding your quote request.') }}
                </p>
            </td>
        </tr>

    </table>

    <p style="margin:16px 0 0; font-size:11.5px; color:#A8A29E;">Powered by {{ $storeName }}</p>

</td>
</tr>
</table>

</body>
</html>