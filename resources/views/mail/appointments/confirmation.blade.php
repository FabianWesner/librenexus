<x-mail::message>
# {{ $isPendingApproval ? __('Your appointment request at :team', ['team' => $teamName]) : __('Your appointment at :team is confirmed', ['team' => $teamName]) }}

{{ __('Hello :name,', ['name' => $customerName]) }}

@if ($isPendingApproval)
{{ __(':team has received your booking request. You will get another email once it is confirmed.', ['team' => $teamName]) }}
@else
{{ __('Your booking with :team is confirmed. Here are the details:', ['team' => $teamName]) }}
@endif

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Staff') }}:** {{ $staffName }}<br>
**{{ __('When') }}:** {{ $localStartsAt }} ({{ $timezone }})
</x-mail::panel>

{{ __('Need to make changes? Use your personal link below to manage this appointment.') }}

<x-mail::button :url="$manageUrl">
{{ __('Manage your appointment') }}
</x-mail::button>

@if ($teamContactEmail)
{{ __('Questions? Contact :team at :email.', ['team' => $teamName, 'email' => $teamContactEmail]) }}
@endif

{{ __('Thanks,') }}<br>
{{ $teamName }}
</x-mail::message>
