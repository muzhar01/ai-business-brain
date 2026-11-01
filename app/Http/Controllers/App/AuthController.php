<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Security\PasswordPolicy;
use App\Security\Sso;
use App\Security\TwoFactor;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email|max:191', 'password' => 'required|string|max:500']);
        $key = 'login:'.Str::lower($credentials['email']);
        $max = config('brain.security.lockout_attempts');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            AuthEvent::log('locked', null, $credentials['email']);

            return back()->withErrors(['email' => __('Too many attempts. Try again in :minutes minutes.', ['minutes' => ceil(RateLimiter::availableIn($key) / 60)])])->onlyInput('email');
        }

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($key, config('brain.security.lockout_minutes') * 60);
            AuthEvent::log('failed', null, $credentials['email']);

            return back()->withErrors(['email' => __('These credentials do not match our records.')])->onlyInput('email');
        }

        RateLimiter::clear($key);
        $user = User::where('email', $credentials['email'])->first();

        if (Sso::passwordBlocked($user)) {
            return back()->withErrors(['email' => __('Your organisation requires single sign-on. Use the button below.')])->onlyInput('email');
        }

        return $this->finishLogin($request, $user, $request->boolean('remember'));
    }

    /** Shared by password and SSO logins: 2FA challenge when enabled, otherwise sign in. */
    public function finishLogin(Request $request, User $user, bool $remember)
    {
        if ($user->two_factor_confirmed_at) {
            $request->session()->put('2fa:user', $user->id);
            $request->session()->put('2fa:remember', $remember);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user, $remember);
    }

    public function showChallenge(Request $request)
    {
        abort_unless($request->session()->has('2fa:user'), 404);

        return view('auth.two-factor');
    }

    public function challenge(Request $request, TwoFactor $twoFactor)
    {
        $userId = $request->session()->get('2fa:user');
        $user = $userId ? User::find($userId) : null;
        abort_unless($user, 404);
        $code = (string) $request->validate(['code' => 'required|string|max:20'])['code'];

        $key = '2fa:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['code' => __('Too many attempts. Wait a few minutes.')]);
        }
        if (! $twoFactor->verify($user, $code)) {
            RateLimiter::hit($key, 300);
            AuthEvent::log('2fa_failed', $user);

            return back()->withErrors(['code' => __('That code is not valid.')]);
        }
        RateLimiter::clear($key);

        return $this->completeLogin($request, $user, (bool) $request->session()->pull('2fa:remember'));
    }

    protected function completeLogin(Request $request, User $user, bool $remember)
    {
        $request->session()->forget('2fa:user');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('last_activity', time());
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        AuthEvent::log('login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function showRegister(Request $request)
    {
        abort_unless(config('saas.allow_registration') || $request->session()->has('invitation'), 403, 'Registration is closed.');

        return view('auth.register', ['invitation' => Invitation::firstWhere('token', $request->session()->get('invitation'))]);
    }

    public function register(Request $request)
    {
        $invitation = Invitation::whereNull('accepted_at')->firstWhere('token', $request->session()->get('invitation'));
        abort_unless(config('saas.allow_registration') || $invitation, 403, 'Registration is closed.');

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:191|unique:users,email',
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
            'company' => [$invitation ? 'nullable' : 'required', 'string', 'max:120'],
        ]);

        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);
        if (User::count() === 1) {
            $user->forceFill(['is_admin' => true])->save(); // first user runs the platform
        }

        if ($invitation) {
            WorkspaceController::acceptInvitation($invitation, $user);
        } else {
            Workspace::createFor($user, $data['company']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('invitation');
        AuthEvent::log('registered', $user);

        return redirect()->route('dashboard')->with('status', __('Welcome! Add an AI key in Settings and upload a few documents to get started.'));
    }

    public function logout(Request $request)
    {
        if ($request->user()) {
            AuthEvent::log('logout', $request->user());
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function showForgot()
    {
        return view('auth.forgot');
    }

    public function sendReset(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return back()->with('status', __('If that email has an account, a reset link is on its way.'));
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function reset(Request $request)
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordPolicy::rule()]]);

        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'password_changed_at' => now()])->save();
            AuthEvent::log('password_changed', $user);
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect('/login')->with('status', __('Password updated. Sign in.'))
            : back()->withErrors(['email' => __($status)]);
    }
}
