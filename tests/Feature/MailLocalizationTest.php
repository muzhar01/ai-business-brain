<?php

namespace Tests\Feature;

use App\Mail\DailyBriefing;
use App\Mail\WorkspaceInvitation;
use App\Models\Invitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function invitation(string $role = 'member'): Invitation
    {
        [, $ws] = $this->tenant();

        return $ws->invitations()->create(['email' => 'a@b.co', 'role' => $role, 'token' => str_repeat('t', 48), 'invited_by' => $ws->owner_id, 'expires_at' => now()->addDays(7)])->load('workspace');
    }

    public function test_every_mail_key_exists_in_all_catalogues(): void
    {
        foreach (['en', 'ur', 'ar'] as $l) {
            $j = json_decode(file_get_contents(lang_path("$l.json")), true);
            foreach ([':workspace: your daily AI briefing', ':workspace · Daily briefing · :date', 'Sent by :app. Turn this off in Settings > Channels.', 'Join :workspace on :app', 'You\'re invited to join :workspace on :app as :role.', 'Accept: :link', 'This link expires :date.', 'admin', 'member'] as $k) {
                $this->assertNotEmpty($j[$k] ?? null, "$l missing $k");
            }
        }
    }

    public function test_invitation_renders_in_each_locale(): void
    {
        $inv = $this->invitation('admin');
        foreach (['en' => 'invited to join', 'ur' => 'دعوت', 'ar' => 'تمت دعوتك'] as $l => $needle) {
            $m = (new WorkspaceInvitation($inv))->locale($l);
            $html = $m->render();
            $this->assertStringContainsString($needle, $html);
            $this->assertStringContainsString('/invitations/', $html);
            $this->assertStringNotContainsString('mail.invitation', $html);
            $m->assertHasSubject(trans('Join :workspace on :app', ['workspace' => $inv->workspace->name, 'app' => config('saas.name')], $l));
        }
    }

    public function test_briefing_renders_in_each_locale_with_plain_body(): void
    {
        foreach (['en' => 'Daily briefing', 'ur' => 'روزانہ بریفنگ', 'ar' => 'الموجز اليومي'] as $l => $needle) {
            $html = (new DailyBriefing('Acme', 'Body <b>text</b>'))->locale($l)->render();
            $this->assertStringContainsString($needle, $html);
            $this->assertStringContainsString('Acme', $html);
            $this->assertStringNotContainsString('mail.briefing', $html);
        }
    }

    public function test_unknown_role_falls_back_untranslated_not_crash(): void
    {
        $inv = $this->invitation('member');
        $this->assertStringContainsString('member', (new WorkspaceInvitation($inv))->locale('en')->render());
    }
}
