<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Team Invitation</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #ddd; padding: 20px;">
        <h2 style="color: #4CAF50;">Hello {{ $name }},</h2>

        <p>
            You have been invited by <strong>{{ $customerName }}</strong> to join their team.
        </p>

        @if(!empty($password))
            <p>
                Your account has been created. Please use the following credentials to log in:
            </p>

            <ul style="background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #eee;">
                <li><strong>Email:</strong> {{ $email }}</li>
                <li><strong>Temporary Password:</strong> {{ $password }}</li>
            </ul>

            <p style="color: #a94442;">
                Please change your password after your first login.
            </p>
        @else
            <p>
                You can log in using your existing account credentials.
            </p>
        @endif

        <p>
            <a href="https://portal.archilance.net/" 
               style="display: inline-block; padding: 10px 20px; background: #4CAF50; color: #fff; 
                      text-decoration: none; border-radius: 4px;">
                Login to Your Account
            </a>
        </p>

        <p>We look forward to working with you!</p>

        <p style="margin-top: 20px;">
            Regards,<br>
            <strong>{{ config('app.name') }}</strong>
        </p>
    </div>
</body>
</html>
