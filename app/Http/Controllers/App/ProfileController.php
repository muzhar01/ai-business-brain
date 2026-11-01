<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\Conversation;
use App\Models\Workspace;
use App\Security\PasswordPolicy;
use App\Security\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show()
    {
        return view('app.profile.show');
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:191', Rule::unique('users')->ignore($user->id)],
        ]);
        $user->update($data);

        return back()->with('status', __('Profile saved.'));
    }

    public function password(Request $request)
    {
        $request->validate(['current_password' => $request->user()->password ? 'required|current_password' : 'nullable', 'password' => ['required', 'confirmed', PasswordPolicy::rule()]]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($request->input('password')), 'password_changed_at' => now()])->save();
        if ($request->user()->getOriginal('password')) {
            Auth::logoutOtherDevices($request->input('password'));
        }
        AuthEvent::log('password_changed', $user);

        return back()->with('status', __('Password changed. Other sessions were signed out.'));
    }

    public function security(Request $request, TwoFactor $twoFactor)
    {
        $user = $request->user();

        return view('app.profile.security', [
            'qr' => $user->two_factor_secret && ! $user->two_factor_confirmed_at ? $twoFactor->qrSvg($user) : null,
            'secret' => $user->two_factor_secret && ! $user->two_factor_confirmed_at ? decrypt($user->two_factor_secret) : null,
            'events' => AuthEvent::where('user_id', $user->id)->latest('id')->limit(25)->get(),
        ]);
    }

    public function enable2fa(Request $request, TwoFactor $twoFactor)
    {
        $request->validate(['current_password' => $request->user()->password ? 'required|current_password' : 'nullable']);
        $twoFactor->begin($request->user());

        return back()->with('status', __('Scan the QR code, then enter a code to confirm.'));
    }

    public function confirm2fa(Request $request, TwoFactor $twoFactor)
    {
        $codes = $twoFactor->confirm($request->user(), (string) $request->validate(['code' => 'required|string|max:10'])['code']);
        if (! $codes) {
            return back()->withErrors(['code' => __('That code is not valid. Check your phone clock and try again.')]);
        }
        AuthEvent::log('2fa_enabled', $request->user());

        return back()->with('recovery_codes', $codes)->with('status', __('Two-factor authentication is on.'));
    }

    public function disable2fa(Request $request, TwoFactor $twoFactor)
    {
        $request->validate(['current_password' => $request->user()->password ? 'required|current_password' : 'nullable']);
        $twoFactor->disable($request->user());
        AuthEvent::log('2fa_disabled', $request->user());

        return back()->with('status', __('Two-factor authentication is off.'));
    }

    /** CCPA right to know / GDPR access: everything we hold about this user. */
    public function export(Request $request)
    {
        $user = $request->user();
        $data = [
            'generated_at' => now()->toIso8601String(),
            'user' => $user->only(['id', 'name', 'email', 'created_at', 'last_login_at', 'two_factor_confirmed_at']),
            'workspaces' => $user->workspaces->map(fn ($w) => ['name' => $w->name, 'role' => $w->pivot->role, 'joined' => $w->pivot->created_at]),
            'conversations' => Conversation::withoutGlobalScopes()->where('user_id', $user->id)->with(['messages' => fn ($q) => $q->withoutGlobalScopes()->whereIn('role', ['user', 'assistant'])])->get()
                ->map(fn ($c) => ['title' => $c->title, 'created_at' => $c->created_at, 'messages' => $c->messages->map->only(['role', 'content', 'created_at'])]),
            'security_events' => AuthEvent::where('user_id', $user->id)->latest('id')->get(['event', 'ip', 'user_agent', 'created_at']),
        ];

        return response()->json($data, 200, ['Content-Disposition' => 'attachment; filename="my-data.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /** CCPA right to delete. Owners must transfer or delete shared workspaces first. */
    public function destroy(Request $request)
    {
        $request->validate(['current_password' => $request->user()->password ? 'required|current_password' : 'nullable', 'confirm' => 'required|in:DELETE']);
        $user = $request->user();

        $blocking = Workspace::where('owner_id', $user->id)->withCount('members')->get()->filter(fn ($w) => $w->members_count > 1);
        if ($blocking->isNotEmpty()) {
            return back()->withErrors(['confirm' => __('Transfer ownership or remove members first: :names', ['names' => $blocking->pluck('name')->implode(', ')])]);
        }

        Workspace::where('owner_id', $user->id)->get()->each(function (Workspace $w) {
            if ($w->subscribed('default')) {
                $w->subscription('default')->cancelNow();
            }
            $w->delete();
        });
        Conversation::withoutGlobalScopes()->where('user_id', $user->id)->update(['user_id' => null, 'user_ref' => null]);

        Auth::logout();
        $user->delete();
        $request->session()->invalidate();

        return redirect('/login')->with('status', __('Your account and personal data were deleted.'));
    }
}
