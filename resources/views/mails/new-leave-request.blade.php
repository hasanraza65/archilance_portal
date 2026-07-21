@extends('mails.layout', ['title' => 'New leave request', 'preheader' => ($sender_name ?? 'An employee') . ' submitted a leave request'])

@section('content')
    <h1 class="h1" style="margin:0 0 16px 0; font-family:Arial,Helvetica,sans-serif; font-size:24px; line-height:30px; font-weight:bold; color:#0f172a;">
        <span style="font-size:24px;">🌴</span>&nbsp;New leave request
    </h1>
    <p style="margin:0 0 20px 0; font-size:15px; line-height:23px; color:#334155;">
        <strong>{{ $sender_name }}</strong> has submitted a new leave request.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0; background-color:#f8fafc; border:1px solid #eef2f7; border-radius:10px;">
        <tr>
            <td style="padding:12px 18px; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b; width:40%;">Leave type</td>
            <td style="padding:12px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; font-weight:bold;" align="right">{{ $leaveType }}</td>
        </tr>
        <tr>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b;">Start date</td>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; font-weight:bold;" align="right">{{ $startDate->format('d M, Y') }}</td>
        </tr>
        <tr>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:Arial,Helvetica,sans-serif; font-size:13px; color:#64748b;">End date</td>
            <td style="padding:12px 18px; border-top:1px solid #eef2f7; font-family:Arial,Helvetica,sans-serif; font-size:14px; color:#0f172a; font-weight:bold;" align="right">{{ $endDate->format('d M, Y') }}</td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 8px 0;">
        <tr>
            <td align="center" bgcolor="#4f46e5" class="btn" style="border-radius:8px; background-color:#4f46e5;">
                <a href="{{ frontendUrl('/leaves') }}" target="_blank" class="btn-a" style="display:inline-block; padding:13px 30px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:#ffffff; text-decoration:none; border-radius:8px;">Review leave requests</a>
            </td>
        </tr>
    </table>
@endsection
