Hello {{ $recipientName }},

{{ $notificationTitle }}

{{ $notificationMessage }}
@if ($notificationActionUrl)

Open this update: {{ $notificationActionUrl }}
@endif

You are receiving this because your Scholarship Portal account has a new notification.
