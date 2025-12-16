<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>New Message Notification</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border: 1px solid #ddd; padding: 20px;">
        <h2 style="color: #1E1E1E;">You’ve received a new message!</h2>

        <p>Hello,</p>

        <p>
            You’ve received a new message on <strong>Archilance LLC</strong>:
        </p>

        <blockquote style="background: #f9f9f9; padding: 15px; border-left: 4px solid #1E1E1E; border-radius: 4px; font-style: italic;">
            {{ $message_text }}
        </blockquote>

        <p>
            Please log in to your account to reply.
        </p>

        <p>
            <a href="https://portal.archilance.net/chat" 
               style="display: inline-block; padding: 10px 20px; background: #1E1E1E; color: #fff; 
                      text-decoration: none; border-radius: 4px;">
                View Message
            </a>
        </p>

        <p style="margin-top: 20px;">
            Regards,<br>
            <strong>{{ config('app.name') }}</strong>
        </p>
    </div>
</body>
</html>
