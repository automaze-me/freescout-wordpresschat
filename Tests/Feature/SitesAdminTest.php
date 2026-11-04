<?php

namespace Modules\WordPressChat\Tests\Feature;

use Modules\WordPressChat\Entities\Site;
use Modules\WordPressChat\Tests\TestCase;

class SitesAdminTest extends TestCase
{
    private function adminSite(): Site
    {
        $site = $this->makeSite([
            'url'    => 'https://shop.example.com',
            'origin' => 'https://shop.example.com',
        ]);
        $site->setSecret('old-secret');
        $site->save();

        return $site;
    }

    public function test_regular_user_gets_403_on_every_route()
    {
        $user = $this->regularUser();
        $site = $this->adminSite();
        $id   = $site->id;

        $this->actingAs($user)->get('/wordpress-chat/sites')->assertStatus(403);
        $this->actingAs($user)->get('/wordpress-chat/sites/create')->assertStatus(403);
        $this->actingAs($user)->post('/wordpress-chat/sites', [])->assertStatus(403);
        $this->actingAs($user)->get("/wordpress-chat/sites/$id/edit")->assertStatus(403);
        $this->actingAs($user)->post("/wordpress-chat/sites/$id", [])->assertStatus(403);
        $this->actingAs($user)->post("/wordpress-chat/sites/$id/delete")->assertStatus(403);
        $this->actingAs($user)->post("/wordpress-chat/sites/$id/secret")->assertStatus(403);

        $this->assertSame(1, Site::where('id', $id)->count());
    }

    public function test_ids_above_the_integer_range_are_404()
    {
        $admin = $this->adminUser();
        $this->adminSite();

        foreach (['2147483648', '9999999999', '99999999999'] as $id) {
            $this->actingAs($admin)->get("/wordpress-chat/sites/$id/edit")->assertStatus(404);
            $this->actingAs($admin)->post("/wordpress-chat/sites/$id", [])->assertStatus(404);
            $this->actingAs($admin)->post("/wordpress-chat/sites/$id/delete")->assertStatus(404);
            $this->actingAs($admin)->post("/wordpress-chat/sites/$id/secret")->assertStatus(404);
        }

        // The largest id that can exist still gets the normal not-found.
        $this->actingAs($admin)->get('/wordpress-chat/sites/2147483647/edit')->assertStatus(404);
    }

    public function test_admin_can_view_pages()
    {
        $admin = $this->adminUser();
        $site  = $this->adminSite();

        $html = $this->actingAs($admin)->get('/wordpress-chat/sites')->assertStatus(200)->getContent();
        $this->assertStringContainsString('Shop', $html);
        $this->actingAs($admin)->get('/wordpress-chat/sites/create')->assertStatus(200);
        $html = $this->actingAs($admin)->get("/wordpress-chat/sites/{$site->id}/edit")->assertStatus(200)->getContent();
        $this->assertStringContainsString($site->public_id, $html);
        $this->assertStringContainsString('old-secret', $html);
    }

    public function test_admin_creates_site()
    {
        $admin   = $this->adminUser();
        $mailbox = $this->testMailbox();

        $this->actingAs($admin)->post('/wordpress-chat/sites', [
            'name'       => 'Shop',
            'url'        => 'https://Shop.Example.com/contact?x=1',
            'mailbox_id' => $mailbox->id,
        ])->assertSessionMissing('errors');

        $this->assertSame(1, Site::count());
        $site = Site::first();
        $this->assertSame('https://shop.example.com', $site->origin);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $site->public_id);
        $this->assertSame(43, strlen($site->getSecret()));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $site->getSecret());
        $this->assertSame(120, (int) $site->fallback_delay);
        $this->assertSame(10, (int) $site->max_attachment_mb);
    }

    public function test_invalid_url_is_rejected()
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->post('/wordpress-chat/sites', [
            'name'       => 'Shop',
            'url'        => 'example.com',
            'mailbox_id' => $this->testMailbox()->id,
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, Site::count());
    }

    public function test_update_changes_fields_but_not_credentials()
    {
        $admin = $this->adminUser();
        $site  = $this->adminSite();

        $this->actingAs($admin)->post("/wordpress-chat/sites/{$site->id}", [
            'name'             => 'Renamed',
            'url'              => 'http://other.test:8080/x',
            'mailbox_id'       => $site->mailbox_id,
            'fallback_delay'   => 300,
            'max_attachment_mb' => 5,
        ])->assertSessionMissing('errors');

        $fresh = $site->fresh();
        $this->assertSame('Renamed', $fresh->name);
        $this->assertSame('http://other.test:8080', $fresh->origin);
        $this->assertSame(300, (int) $fresh->fallback_delay);
        $this->assertSame($site->public_id, $fresh->public_id);
        $this->assertSame('old-secret', $fresh->getSecret());
    }

    public function test_regenerate_secret_changes_it()
    {
        $admin = $this->adminUser();
        $site  = $this->adminSite();

        $this->actingAs($admin)->post("/wordpress-chat/sites/{$site->id}/secret");

        $new = $site->fresh()->getSecret();
        $this->assertNotSame('old-secret', $new);
        $this->assertSame(43, strlen($new));
    }

    public function test_delete_removes_site()
    {
        $admin = $this->adminUser();
        $site  = $this->adminSite();

        $this->actingAs($admin)->post("/wordpress-chat/sites/{$site->id}/delete");

        $this->assertSame(0, Site::count());
    }

    public function test_non_numeric_mailbox_id_is_a_validation_error()
    {
        $admin = $this->adminUser();
        $before = Site::count();

        foreach (['abc', '99999999999'] as $value) {
            $this->actingAs($admin)->from('/wordpress-chat/sites/create')
                ->post('/wordpress-chat/sites', [
                    'name'       => 'Shop',
                    'url'        => 'https://shop.example.com',
                    'mailbox_id' => $value,
                ])
                ->assertRedirect('/wordpress-chat/sites/create')
                ->assertSessionHasErrors('mailbox_id');
        }

        $this->assertSame($before, Site::count());
    }
}
