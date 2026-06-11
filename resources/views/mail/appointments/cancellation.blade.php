<x-mail::message>
# Your appointment was cancelled

Hello {{ $customerName }},

your appointment with **{{ $teamName }}** has been cancelled:

- **Service:** {{ $serviceName }}
- **With:** {{ $staffName }}
- **Was scheduled for:** {{ $localStartsAt }} ({{ $timezone }})

If this comes as a surprise or you would like to book a new time, please
get in touch with {{ $teamName }}@if ($teamContactEmail) at {{ $teamContactEmail }}@endif or book again online.

Thanks,<br>
{{ $teamName }}
</x-mail::message>
