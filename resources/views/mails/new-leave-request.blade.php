<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Leave Request</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #ddd; padding: 20px;">
        
        <h4 style="color: #1E1E1E;">You’ve received a new leave request!</h4>

        <p>Hello,</p>

        <p>
            <strong>{{ $sender_name }}</strong> has submitted a new leave request.
        </p>

        <p style="background: #f2f2f2; padding: 10px 15px; border-radius: 4px; border-left: 4px solid #1E1E1E;">
            <strong>Leave Type:</strong> {{ $leaveType }}<br>
            <strong>Start Date:</strong> {{ $startDate->format('d M, Y') }}<br>
            <strong>End Date:</strong> {{ $endDate->format('d M, Y') }}
        </p>

        <p>
            Please log in to your account to approve or reject this leave request.
        </p>
        
        

        <p>
            <a href="https://portal.archilance.net/employeeleaves" 
               style="display: inline-block; padding: 10px 20px; background: #1E1E1E; color: #fff; 
                      text-decoration: none; border-radius: 4px;">
                View Leave Requests
            </a>
        </p>

        <p style="margin-top: 20px;">
            Regards,<br>
            <strong>{{ config('app.name') }}</strong>
        </p>
    </div>
</body>
</html>
