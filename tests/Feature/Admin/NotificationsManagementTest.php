<?php

namespace Tests\Feature\Admin;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Tests\TestCase;

class NotificationsManagementTest extends TestCase
{
    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->superAdmin = User::factory()->superAdmin()->create(['email_verified_at' => now()]);
    }

    public function test_admin_can_list_mark_read_and_delete_notifications(): void
    {
        $notification = Notification::query()->create([
            'user_id' => $this->superAdmin->id,
            'channel' => 'email',
            'type' => 'critical_alert',
            'subject' => 'Critical finding detected',
            'body' => 'A critical finding was raised.',
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.notifications.index'))
            ->assertOk()
            ->assertSee('Critical finding detected');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.notifications.read', $notification))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.notifications.destroy', $notification))
            ->assertRedirect();

        $this->assertSoftDeleted('notifications', ['id' => $notification->id]);
    }

    public function test_unread_filter_hides_read_notifications(): void
    {
        Notification::query()->create([
            'channel' => 'email',
            'type' => 'info',
            'subject' => 'Unread item',
            'body' => 'body',
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
        ]);

        Notification::query()->create([
            'channel' => 'email',
            'type' => 'info',
            'subject' => 'Already read',
            'body' => 'body',
            'status' => NotificationStatus::Sent,
            'sent_at' => now(),
            'read_at' => now(),
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.notifications.index', ['unread' => 1]))
            ->assertOk()
            ->assertSee('Unread item')
            ->assertDontSee('Already read');
    }
}
