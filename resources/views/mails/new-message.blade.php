@extends('mails.layout', ['title' => 'New message', 'preheader' => 'You have a new message on Archilance'])

@section('content')
    <h1 class="h1" style="margin:0 0 16px 0; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:30px; font-weight:bold; color:#0f172a;">
        <span style="font-size:24px;">💬</span>&nbsp;New message
    </h1>
    <p style="margin:0 0 20px 0; font-size:15px; line-height:23px; color:#334155;">
        You've received a new message on <strong>Archilance</strong>.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
        <tr>
            <td style="background-color:#f8fafc; border-left:4px solid #4f46e5; border-radius:8px; padding:16px 18px; font-family:Arial,Helvetica,sans-serif; font-size:15px; line-height:23px; color:#475569;">
                {!! nl2br(e($message_text)) !!}
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 8px 0;">
        <tr>
            <td align="center" bgcolor="#4f46e5" class="btn" style="border-radius:8px; background-color:#4f46e5;">
                <a href="{{ frontendUrl('/chat') }}" target="_blank" class="btn-a" style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">Open chat</a>
            </td>
        </tr>
    </table>
@endsection
