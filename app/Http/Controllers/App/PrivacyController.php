<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Task;
use App\Saas\Quota;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;

/**
 * Privacy center for a workspace's own customers (CCPA §1798.100/105/110, GDPR art. 15/17):
 * find everything held about a person, export it, or erase it. Also retention + HIPAA mode.
 */
class PrivacyController extends Controller
{
    public function index(Request $request)
    {
        $subject = $request->query('subject');

        return view('app.settings.privacy', [
            'subject' => $subject,
            'found' => $subject ? $this->find($subject) : null,
            'hipaaProviders' => config('brain.security.hipaa_providers'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'retention_days' => 'nullable|integer|min:0|max:3650',
            'session_timeout' => 'nullable|integer|min:0|max:1440',
            'privacy_notice' => 'nullable|string|max:500',
        ]);
        $hipaa = $request->boolean('hipaa_mode');
        if ($hipaa) {
            Quota::feature(Tenant::current(), 'hipaa');
        }
        Tenant::current()->updateSettings([
            'retention_days' => (int) ($data['retention_days'] ?? 0),
            'session_timeout' => (int) ($data['session_timeout'] ?? 0),
            'privacy_notice' => $data['privacy_notice'] ?? null,
            'require_2fa' => $request->boolean('require_2fa') || $hipaa,
            'hipaa_mode' => $hipaa,
        ]);
        AuditLog::record('privacy.settings_updated', null, ['hipaa_mode' => $hipaa, 'retention_days' => (int) ($data['retention_days'] ?? 0)]);

        return back()->with('status', __('Privacy & compliance settings saved.'));
    }

    public function export(Request $request)
    {
        $subject = (string) $request->validate(['subject' => 'required|string|min:3|max:191'])['subject'];
        $found = $this->find($subject);
        AuditLog::record('privacy.dsr_export', null, ['subject_hash' => hash('sha256', mb_strtolower($subject))]);

        $payload = [
            'subject' => $subject,
            'generated_at' => now()->toIso8601String(),
            'business' => Tenant::current()->name,
            'leads' => $found['leads'],
            'tasks' => $found['tasks'],
            'conversations' => $found['conversations']->map(fn ($c) => [
                'channel' => $c->channel, 'started' => $c->created_at,
                'messages' => $c->messages()->whereIn('role', ['user', 'assistant'])->get()->map->only(['role', 'content', 'created_at']),
            ]),
        ];

        return response()->json($payload, 200, ['Content-Disposition' => 'attachment; filename="data-request.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function erase(Request $request)
    {
        $subject = (string) $request->validate(['subject' => 'required|string|min:3|max:191', 'confirm' => 'required|in:ERASE'])['subject'];
        $found = $this->find($subject);

        $counts = ['leads' => $found['leads']->count(), 'tasks' => $found['tasks']->count(), 'conversations' => $found['conversations']->count()];
        Task::whereIn('id', $found['tasks']->pluck('id'))->delete();
        Lead::whereIn('id', $found['leads']->pluck('id'))->delete();
        foreach ($found['conversations'] as $c) {
            $c->delete(); // messages + actions cascade
        }
        AuditLog::record('privacy.dsr_erase', null, $counts + ['subject_hash' => hash('sha256', mb_strtolower($subject))]);

        return redirect()->route('settings.privacy')->with('status', __('Erased :leads leads, :tasks tasks and :conversations conversations.', $counts));
    }

    /** Matches email, phone, name or a channel id (tg:123, wa:+92..., web:visitor). */
    protected function find(string $subject): array
    {
        $s = trim($subject);
        $leads = Lead::where(fn ($q) => $q->where('email', $s)->orWhere('phone', $s)->orWhere('name', $s))->get();
        $refs = array_filter([$s, 'tg:'.$s, 'wa:'.$s, 'web:'.$s]);
        $conversations = Conversation::where(fn ($q) => $q->whereIn('external_ref', $refs)->orWhere('user_ref', $s))->get();

        // conversations whose text mentions the email/phone (widget visitors who typed it)
        if (filter_var($s, FILTER_VALIDATE_EMAIL) || preg_match('/^\+?\d{7,}$/', $s)) {
            $ids = Message::where('role', 'user')->whereNotIn('conversation_id', $conversations->pluck('id'))->latest('id')->limit(20000)->get(['id', 'conversation_id', 'content'])
                ->filter(fn ($m) => str_contains(mb_strtolower((string) $m->content), mb_strtolower($s)))->pluck('conversation_id')->unique();
            $conversations = $conversations->merge(Conversation::whereIn('id', $ids)->get());
        }

        return [
            'leads' => $leads,
            'tasks' => Task::whereIn('lead_id', $leads->pluck('id'))->get(),
            'conversations' => $conversations->unique('id')->values(),
        ];
    }
}
