<x-mail::message>
# {{ __('Reminder: your appointment at :team', ['team' => $teamName]) }}

{{ __('Hello :name,', ['name' => $customerName]) }}

{{ __('This is a friendly reminder of your upcoming appointment with :team:', ['team' => $teamName]) }}

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Staff') }}:** {{ $staffName }}<br>
**{{ __('When') }}:** {{ $localStartsAt }} ({{ $timezone }})
</x-mail::panel>

{{ __('Need to cancel or reschedule? Use the manage link from your confirmation email.') }}

@if ($teamContactEmail)
{{ __('Questions? Contact :team at :email.', ['team' => $teamName, 'email' => $teamContactEmail]) }}
@endif

{{ __('See you soon,') }}<br>
{{ $teamName }}
</x-mail::message>
