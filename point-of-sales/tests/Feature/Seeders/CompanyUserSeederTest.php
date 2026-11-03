<?php

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\CompanyUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompanyUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('WMS_SUPERADMIN_PASSWORD');
        putenv('WMS_ADMIN_PASSWORD');
        putenv('WMS_ADMIN2_PASSWORD');
        putenv('WMS_POS_PINS');

        parent::tearDown();
    }

    #[Test]
    public function credentials_come_from_env(): void
    {
        putenv('WMS_ADMIN_PASSWORD=Str0ng-Admin-Pass');
        putenv('WMS_POS_PINS=WH-PHRANON:4829');

        $this->seed(CompanyUserSeeder::class);

        $this->assertTrue(Hash::check('Str0ng-Admin-Pass', User::where('email', 'admin@wms.local')->value('password')));
        $this->assertTrue(Hash::check('4829', Warehouse::where('code', 'WH-PHRANON')->value('pos_pin')));
    }

    #[Test]
    public function rerunning_without_env_keeps_existing_password_and_pins(): void
    {
        putenv('WMS_ADMIN_PASSWORD=Str0ng-Admin-Pass');
        putenv('WMS_POS_PINS=WH-PHRANON:4829');
        $this->seed(CompanyUserSeeder::class);

        putenv('WMS_ADMIN_PASSWORD');
        putenv('WMS_POS_PINS');
        $this->seed(CompanyUserSeeder::class);

        $this->assertTrue(Hash::check('Str0ng-Admin-Pass', User::where('email', 'admin@wms.local')->value('password')));
        $this->assertTrue(Hash::check('4829', Warehouse::where('code', 'WH-PHRANON')->value('pos_pin')));
    }

    #[Test]
    public function superadmin_holds_everything_and_admins_cannot_manage_users(): void
    {
        putenv('WMS_SUPERADMIN_PASSWORD=Sup3r-Admin-Pass');
        putenv('WMS_ADMIN_PASSWORD=Str0ng-Admin-Pass');
        putenv('WMS_ADMIN2_PASSWORD=An0ther-Admin-Pass');

        $this->seed(CompanyUserSeeder::class);

        $superadmin = User::where('email', 'superadmin@wms.local')->firstOrFail();
        $this->assertTrue(Hash::check('Sup3r-Admin-Pass', $superadmin->password));
        $this->assertTrue($superadmin->isSuperAdmin());
        $this->assertTrue($superadmin->can('users-create'));

        foreach (['admin@wms.local' => 'Str0ng-Admin-Pass', 'admin2@wms.local' => 'An0ther-Admin-Pass'] as $email => $password) {
            $admin = User::where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check($password, $admin->password));
            $this->assertNotNull($admin->email_verified_at);
            $this->assertFalse($admin->isSuperAdmin());
            $this->assertTrue($admin->isWarehouseAdmin());
            $this->assertTrue($admin->can('stock-mutations-access'));
            $this->assertTrue($admin->can('products-export'));
            $this->assertFalse($admin->can('users-access'));
            $this->assertFalse($admin->can('roles-access'));
            $this->assertFalse($admin->can('permissions-access'));
            $this->assertSame(
                $superadmin->outlets()->pluck('outlets.id')->sort()->values()->all(),
                $admin->outlets()->pluck('outlets.id')->sort()->values()->all()
            );

            $this->actingAs($admin)->get(route('users.index'))->assertForbidden();
        }
    }

    #[Test]
    public function rerunning_strips_user_management_from_an_admin_seeded_as_super_admin(): void
    {
        $this->seed(CompanyUserSeeder::class);
        $admin = User::where('email', 'admin@wms.local')->firstOrFail();
        $admin->syncRoles(['super-admin']);
        $admin->givePermissionTo('users-access');

        $this->seed(CompanyUserSeeder::class);

        $admin->refresh();
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertFalse($admin->can('users-access'));
    }
}
