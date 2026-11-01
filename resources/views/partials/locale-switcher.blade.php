{{-- Interface language switcher (ADR 0001). compact: sidebar / guest pages; otherwise the profile form with "Workspace default". --}}
@php($compact = $compact ?? false)
@php($fieldId = $fieldId ?? ($compact ? 'locale-compact' : 'locale-profile'))
@php($current = $compact ? app()->getLocale() : (auth()->user()?->locale ?? ''))
<form method="post" action="{{ route('locale.update') }}" class="{{ $compact ? 'langsw' : '' }}">@csrf
  <label for="{{ $fieldId }}">{{ __('Language') }}</label>
  <div class="row">
    <select id="{{ $fieldId }}" name="locale" autocomplete="off">
      @unless($compact)<option value="" @selected($current === '')>{{ __('Workspace default') }}</option>@endunless
      @foreach(\App\Support\Locale::supported() as $code => $name)
        <option value="{{ $code }}" lang="{{ \App\Support\Direction::lang($code) }}" dir="{{ \App\Support\Direction::of($code) }}" @selected($current === $code)>{{ $name }}</option>
      @endforeach
    </select>
    <button class="btn {{ $compact ? 'ghost sm' : '' }}">{{ $compact ? __('Apply') : __('Save') }}</button>
  </div>
  @unless($compact)<div class="hint">{{ __('Choose the language of menus and pages. “Workspace default” follows your workspace settings, then your browser.') }}</div>@endunless
</form>
