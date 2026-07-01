<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Password Reset</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #ddd; padding: 20px;">
        <h2 style="color: #4CAF50;">Hello {{ $name }},</h2>

        <p>
            We received a request to reset the password for your Archilance account.
            A new temporary password has been generated for you:
        </p>

        <ul style="background: #f9f9f9; padding: 15px; border-radius: 5px; border: 1px solid #eee;">
            <li><strong>Email:</strong> {{ $email }}</li>
            <li><strong>Temporary Password:</strong> {{ $password }}</li>
        </ul>

        <p style="color: #a94442;">
            For your security, please log in and change this password immediately.
        </p>

        <p>
            <a href="https://archilance.org/"
               style="display: inline-block; padding: 10px 20px; background: #4CAF50; color: #fff;
                      text-decoration: none; border-radius: 4px;">
                Login to Your Account
            </a>
        </p>

        <p>
            If you did not request a password reset, please contact us immediately and
            secure your account.
        </p>

        <p style="margin-top: 20px;">
            Regards,<br>
            <strong>{{ config('app.name') }}</strong>
        </p>
    </div>
</body>
</html>
