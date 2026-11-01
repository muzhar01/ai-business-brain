<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function create()
    {
        return view('auth.workspace');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        Workspace::createFor($request->user(), $data['name']);

        return redirect()->route('dashboard')->with('status', __('Workspace :name created.', ['name' => $data['name']]));
    }

    public function switch(Request $request)
    {
        $id = (int) $request->input('workspace_id');
        abort_unless($request->user()->workspaces()->whereKey($id)->exists(), 403);
        $request->user()->forceFill(['current_workspace_id' => $id])->save();

        return redirect()->route('dashboard');
    }

    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::with('workspace')->whereNull('accepted_at')->where('token', $token)->firstOrFail();
        abort_if($invitation->expires_at && $invitation->expires_at->isPast(), 410, 'This invitation has expired.');

        if (! $request->user()) {
            $request->session()->put('invitation', $token);

            return User::where('email', $invitation->email)->exists()
                ? redirect('/login')->with('status', __('Sign in to join :name, then open the link again.', ['name' => $invitation->workspace->name]))
                : redirect('/register');
        }

        self::acceptInvitation($invitation, $request->user());

        return redirect()->route('dashboard')->with('status', __('You joined :name.', ['name' => $invitation->workspace->name]));
    }

    public static function acceptInvitation(Invitation $invitation, User $user): void
    {
        $workspace = $invitation->workspace;
        $workspace->members()->syncWithoutDetaching([$user->id => ['role' => $invitation->role]]);
        $invitation->update(['accepted_at' => now()]);
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        Tenant::run($workspace, fn () => AuditLog::record('member.joined', $user, ['role' => $invitation->role]));
    }
}
