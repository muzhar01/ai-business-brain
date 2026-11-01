{{ __('You\'re invited to join :workspace on :app as :role.', ['workspace' => $invitation->workspace->name, 'app' => config('saas.name'), 'role' => __($invitation->role)]) }}

{{ __('Accept: :link', ['link' => route('invitations.accept', $invitation->token)]) }}

{{ __('This link expires :date.', ['date' => $invitation->expires_at?->locale(app()->getLocale())->isoFormat('LL')]) }}
