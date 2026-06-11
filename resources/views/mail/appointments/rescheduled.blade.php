<x-mail::message>
# {{ __('Your appointment at :team was rescheduled', ['team' => $teamName]) }}

{{ __('Hello :name,', ['name' => $customerName]) }}

{{ __('Your appointment with :team has been moved to a new time. Here are the updated details:', ['team' => $teamName]) }}

<x-mail::panel>
**{{ __('Service') }}:** {{ $serviceName }}<br>
**{{ __('Staff') }}:** {{ $staffName }}<br>
**{{ __('New time') }}:** {{ $localStartsAt }} ({{ $timezone }})
</x-mail::panel>

@if ($manageUrl)
{{ __('Need to make further changes? Use your personal link below to manage this appointment.') }}

<x-mail::button :url="$manageUrl">
{{ __('Manage your appointment') }}
</x-mail::button>
@else
{{ __('Need to make further changes? Use the manage link from your confirmation email.') }}
@endif

@if ($teamContactEmail)
{{ __('Questions? Contact :team at :email.', ['team' => $teamName, 'email' => $teamContactEmail]) }}
@endif

{{ __('Thanks,') }}<br>
{{ $teamName }}
</x-mail::message>
