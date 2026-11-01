<h1>{{ __('Settings') }}</h1>
<nav class="row tabs" aria-label="{{ __('Settings sections') }}" style="margin:10px 0 18px">
@foreach(['settings.general' => __('General'), 'settings.providers' => __('AI providers'), 'settings.api' => __('API'), 'settings.webhooks' => __('Webhooks'), 'settings.channels' => __('Channels'), 'settings.team' => __('Team'), 'settings.privacy' => __('Privacy & compliance'), 'settings.data' => __('Data & audit')] as $r => $l)
<a class="btn {{ request()->routeIs($r) ? '' : 'ghost' }} sm" href="{{ route($r) }}" @if(request()->routeIs($r)) aria-current="page" @endif>{{ $l }}</a>
@endforeach
</nav>
