<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SupportContactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * System Settings > Support Contact: the IT company shown in the User Manual.
 * Every user can read it; only a super admin can change it.
 */
class SupportContactTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $staff;

    private array $contact = [
        'company'        => 'Acme IT Solutions',
        'contact_person' => 'Support Desk',
        'phone'          => '+94 11 234 5678',
        'whatsapp'       => '+94 77 123 4567',
        'email'          => 'support@acme.lk',
        'website'        => 'https://acme.lk',
        'hours'          => 'Mon-Fri 8.30am - 5.30pm',
        'notes'          => 'Have the document number ready.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $this->superAdmin = User::factory()->create(['role' => UserRole::SuperAdmin]);
        $this->superAdmin->assignRole('super_admin');

        $this->staff = User::factory()->create();
    }

    public function test_every_field_is_empty_before_it_is_set(): void
    {
        $this->actingAs($this->staff)->getJson('/api/system/support-contact')
            ->assertOk()
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.phone', null);
    }

    public function test_guests_cannot_read_it(): void
    {
        $this->getJson('/api/system/support-contact')->assertUnauthorized();
    }

    public function test_super_admin_saves_and_every_user_sees_it_at_once(): void
    {
        // Prime the cache with the empty value first.
        $this->actingAs($this->staff)->getJson('/api/system/support-contact')->assertOk();

        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/support-contact', $this->contact)
            ->assertOk()
            ->assertJsonPath('data.company', 'Acme IT Solutions');

        // The cached setting was refreshed for everyone.
        $this->actingAs($this->staff)->getJson('/api/system/support-contact')
            ->assertOk()
            ->assertJsonPath('data.email', 'support@acme.lk')
            ->assertJsonPath('data.hours', 'Mon-Fri 8.30am - 5.30pm');
    }

    public function test_staff_cannot_change_it(): void
    {
        $this->actingAs($this->staff)
            ->putJson('/api/system/support-contact', $this->contact)
            ->assertForbidden();

        $this->assertNull(app(SupportContactService::class)->get()['company']);
    }

    public function test_invalid_email_and_website_are_rejected(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/support-contact', ['email' => 'not-an-email', 'website' => 'acme'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'website']);
    }

    public function test_blank_fields_are_stored_as_empty(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/system/support-contact', ['company' => '  ', 'phone' => '011 222 3333'])
            ->assertOk()
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.phone', '011 222 3333');
    }

    public function test_changes_are_audited_and_an_unchanged_save_is_not(): void
    {
        $this->actingAs($this->superAdmin)->putJson('/api/system/support-contact', $this->contact)->assertOk();
        $this->actingAs($this->superAdmin)->putJson('/api/system/support-contact', $this->contact)->assertOk();

        $events = AuditLog::where('auditable_type', 'global_setting')->get();

        $this->assertCount(1, $events);
        $this->assertSame('support_contact_updated', $events[0]->event);
        $this->assertSame($this->superAdmin->id, $events[0]->user_id);
        $this->assertSame('Acme IT Solutions', $events[0]->new_values['company']);
    }

    public function test_the_generic_settings_endpoint_cannot_bypass_validation(): void
    {
        $this->actingAs($this->superAdmin)
            ->putJson('/api/settings/support.contact', ['value' => ['email' => 'bad']])
            ->assertUnprocessable();
    }
}
