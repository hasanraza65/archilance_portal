@extends('mails.layout', ['title' => 'Password reset', 'preheader' => 'Your temporary Archilance password is inside'])

@section('content')
    <h1 class="h1" style="margin:0 0 16px 0; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:30px; font-weight:bold; color:#0f172a;">
        <span style="font-size:24px;">🔑</span>&nbsp;Password reset
    </h1>
    <p style="margin:0 0 12px 0; font-size:15px; line-height:23px; color:#334155;">Hi {{ $name }},</p>
    <p style="margin:0 0 20px 0; font-size:15px; line-height:23px; color:#334155;">
        We received a request to reset your Archilance password. Here is a new temporary password for your account:
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px 0; background-color:#f8fafc; border:1px solid #eef2f7; border-radius:10px;">
        <tr>
            <td style="padding:12px 18px; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b; width:40%;">Email</td>
            <td style="padding:12px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; font-weight:bold;" align="right">{{ $email }}</td>
        </tr>
        <tr>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b;">Temporary password</td>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:'Courier New',Courier,monospace; font-size:15px; color:#0f172a; font-weight:bold;" align="right">{{ $password }}</td>
        </tr>
    </table>

    <p style="margin:0 0 22px 0; font-size:14px; line-height:22px; color:#9a3412; background-color:#fff7ed; border:1px solid #fed7aa; border-radius:8px; padding:12px 16px;">
        For your security, please log in and change this password right away.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 8px 0;">
        <tr>
            <td align="center" bgcolor="#4f46e5" class="btn" style="border-radius:8px; background-color:#4f46e5;">
                <a href="{{ frontendUrl('/login') }}" target="_blank" class="btn-a" style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">Log in to your account</a>
            </td>
        </tr>
    </table>

    <p style="margin:22px 0 0 0; font-size:13px; line-height:20px; color:#94a3b8;">
        If you didn't request a password reset, please contact us immediately and secure your account.
    </p>
@endsection
