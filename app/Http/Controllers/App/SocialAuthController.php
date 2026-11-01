<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Security\Sso;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class SocialAuthController extends Controller
{
    public function redirect(string $provider)
    {
        abort_unless(array_key_exists($provider, Sso::enabled()), 404);
        $driver = Socialite::driver($provider);

        return ($provider === 'github' ? $driver->scopes(['user:email']) : $driver)->redirect();
    }

    public function callback(Request $request, string $provider, Sso $sso, AuthController $auth)
    {
        abort_unless(array_key_exists($provider, Sso::enabled()), 404);

        try {
            $identity = Socialite::driver($provider)->user();
        } catch (Throwable $e) {
            report($e);

            return redirect('/login')->withErrors(['email' => __('Sign-in with :provider failed. Please try again.', ['provider' => Sso::PROVIDERS[$provider]])]);
        }

        $user = $sso->resolve($provider, $identity);

        return $auth->finishLogin($request, $user, true);
    }
}
