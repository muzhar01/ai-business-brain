<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Dataset;
use App\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Static WCAG 2.2 checks over every rendered app page (ADR 0021). Not a substitute for screen-reader testing. */
class AccessibilityAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/dashboard', '/chat', '/knowledge', '/approvals', '/agents', '/leads', '/tasks', '/datasets',
        '/imports', '/exports', '/settings', '/settings/providers', '/settings/api', '/settings/channels', '/settings/team',
        '/settings/data', '/billing', '/admin', '/profile'];

    /** @return array<string, \DOMXPath> */
    private function pages(): array
    {
        [$user] = $this->tenant();
        $user->forceFill(['is_admin' => true])->save();
        $agent = Agent::first();
        $dataset = Dataset::create(['name' => 'Products', 'fields' => [['name' => 'sku', 'type' => 'string']]]);
        Tenant::forget();
        $out = [];
        foreach ([...self::PAGES, "/agents/{$agent->id}/edit", "/datasets/{$dataset->id}"] as $url) {
            $res = $this->actingAs($user)->get($url);
            $res->assertOk();
            $doc = new \DOMDocument;
            libxml_use_internal_errors(true);
            $doc->loadHTML('<?xml encoding="utf-8"?>'.$res->getContent());
            libxml_clear_errors();
            $out[$url] = new \DOMXPath($doc);
            Tenant::forget();
        }

        return $out;
    }

    public function test_structure_and_names(): void
    {
        foreach ($this->pages() as $url => $x) {
            $this->assertNotSame('', trim($x->evaluate('string(//html/@lang)')), "$url: html lang");
            $this->assertNotSame('', trim($x->evaluate('string(//title)')), "$url: title");
            $this->assertSame(1, $x->query('//main')->length, "$url: exactly one main");
            $this->assertSame(1, $x->query('//h1')->length, "$url: exactly one h1");
            $this->assertSame(0, $x->query('//img[not(@alt)]')->length, "$url: img without alt");
            $ids = [];
            foreach ($x->query('//*[@id]') as $n) {
                $id = $n->getAttribute('id');
                $this->assertArrayNotHasKey($id, $ids, "$url: duplicate id $id");
                $ids[$id] = 1;
            }
            foreach ($x->query('//button') as $b) {
                $name = trim($b->textContent).$b->getAttribute('aria-label').$b->getAttribute('title');
                $this->assertNotSame('', trim($name), "$url: unnamed button ".$b->getAttribute('id'));
            }
            foreach ($x->query('//a[@href]') as $a) {
                $name = trim($a->textContent).$a->getAttribute('aria-label').$a->getAttribute('title');
                $this->assertNotSame('', trim($name), "$url: unnamed link ".$a->getAttribute('href'));
            }
        }
    }

    public function test_every_form_control_has_an_accessible_name(): void
    {
        foreach ($this->pages() as $url => $x) {
            foreach ($x->query('//input[not(@type="hidden" or @type="submit" or @type="button")]|//select|//textarea') as $c) {
                $id = $c->getAttribute('id');
                $named = $c->getAttribute('aria-label') !== '' || $c->getAttribute('aria-labelledby') !== ''
                    || $c->getAttribute('title') !== ''
                    || ($id !== '' && $x->query('//label[@for="'.$id.'"]')->length > 0)
                    || $x->query('ancestor::label', $c)->length > 0;
                $this->assertTrue($named, "$url: unlabelled control name=".$c->getAttribute('name'));
            }
        }
    }

    public function test_aria_references_resolve(): void
    {
        foreach ($this->pages() as $url => $x) {
            foreach (['aria-controls', 'aria-labelledby', 'aria-describedby'] as $attr) {
                foreach ($x->query("//*[@$attr]") as $n) {
                    foreach (preg_split('/\s+/', trim($n->getAttribute($attr))) as $ref) {
                        $this->assertGreaterThan(0, $x->query('//*[@id="'.$ref.'"]')->length, "$url: $attr -> #$ref missing");
                    }
                }
            }
        }
    }

    public function test_css_target_size_and_focus_not_obscured(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        $this->assertStringContainsString('scroll-padding-top', $css, 'SC 2.4.11 focus not obscured by sticky topbar');
        $this->assertStringContainsString('min-height:24px', $css, 'SC 2.5.8 target size');
        $this->assertStringContainsString('prefers-contrast', $css);
    }
}
