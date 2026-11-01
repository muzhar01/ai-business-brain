{{ __(':workspace · Daily briefing · :date', ['workspace' => $workspaceName, 'date' => now()->locale(app()->getLocale())->isoFormat('LL')]) }}

{!! $body !!}

--
{{ __('Sent by :app. Turn this off in Settings > Channels.', ['app' => config('saas.name')]) }}
