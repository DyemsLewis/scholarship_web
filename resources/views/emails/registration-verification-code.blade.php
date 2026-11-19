Hello {{ $recipientName }},

Use this verification code to {{ $resumingAccount ? 'verify the email of your existing' : 'finish creating your' }} Scholarship Portal account:

{{ $verificationCode }}

This code expires in {{ $expiresInMinutes }} minutes. If you did not request this code, you can ignore this email. {{ $resumingAccount ? 'Your existing account details have not been changed.' : 'No account has been created yet.' }}

Scholarship Portal
