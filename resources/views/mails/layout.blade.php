@php
    /**
     * Shared Archilance email shell — professional, mobile-friendly, bulletproof HTML.
     * Emails @extends this and fill @section('content').
     * Optional vars: $title, $preheader, $accent (hex, no #).
     */
    $accent = $accent ?? '4f46e5';        // indigo-600
    $accentDark = $accentDark ?? '4338ca'; // indigo-700
    $brand = $brand ?? 'Archilance';
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title ?? $brand }}</title>
    <style>
        /* Client resets */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #f4f5f7; }
        a { color: #{{ $accent }}; }
        .btn:hover { background-color: #{{ $accentDark }} !important; }

        /* Mobile */
        @media screen and (max-width: 620px) {
            .container { width: 100% !important; }
            .px { padding-left: 22px !important; padding-right: 22px !important; }
            .py { padding-top: 26px !important; padding-bottom: 26px !important; }
            .btn-a { display: block !important; width: 100% !important; box-sizing: border-box; text-align: center !important; }
            .stack { display: block !important; width: 100% !important; }
            h1.h1 { font-size: 22px !important; line-height: 28px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#f4f5f7;">
    <!-- Preheader (hidden preview text) -->
    <div style="display:none; font-size:1px; color:#f4f5f7; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all;">
        {{ $preheader ?? '' }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f4f5f7;">
        <tr>
            <td align="center" style="padding:28px 12px;">

                <!-- Brand -->
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                    <tr>
                        <td style="padding:0 6px 16px 6px;" align="left">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <span style="display:inline-block; width:34px; height:34px; background-color:#{{ $accent }}; border-radius:8px; color:#ffffff; font-family:Arial,Helvetica,sans-serif; font-size:18px; font-weight:bold; line-height:34px; text-align:center;">A</span>
                                    </td>
                                    <td style="vertical-align:middle; padding-left:10px;">
                                        <span style="font-family:Arial,Helvetica,sans-serif; font-size:18px; font-weight:bold; color:#1f2937; letter-spacing:0.2px;">{{ $brand }}</span>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <!-- Card -->
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#ffffff; border-radius:14px; border:1px solid #e5e7eb; overflow:hidden;">
                    <tr>
                        <td style="height:4px; background-color:#{{ $accent }}; line-height:4px; font-size:4px;">&nbsp;</td>
                    </tr>
                    <tr>
                        <td class="px py" style="padding:34px 40px 34px 40px; font-family:Arial,Helvetica,sans-serif; color:#334155;">
                            @yield('content')
                        </td>
                    </tr>
                </table>

                <!-- Footer -->
                <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
                    <tr>
                        <td style="padding:22px 24px 6px 24px; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:18px; color:#94a3b8; text-align:center;">
                            You're receiving this email because you're a member on {{ $brand }}.<br>
                            &copy; {{ date('Y') }} {{ $brand }} LLC. All rights reserved.
                        </td>
                    </tr>
                </table>

            </td>
        </tr>
    </table>
</body>
</html>
