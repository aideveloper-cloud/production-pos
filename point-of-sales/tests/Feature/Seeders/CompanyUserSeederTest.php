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
        putenv('WMS_ADMIN_PASSWORD');
        putenv('WMS_POS_PINS');

        parent::tearDown();
    }

    #[Test]
    public function credentials_come_from_env(): void
    {
        putenv('WMS_ADMIN_PASSWORD=Str0ng-Admin-Pass');
        putenv('WMS_POS_PINS=WH-PHRANON:482913');

        $this->seed(CompanyUserSeeder::class);

        $this->assertTrue(Hash::check('Str0ng-Admin-Pass', User::where('email', 'admin@wms.local')->value('password')));
        $this->assertTrue(Hash::check('482913', Warehouse::where('code', 'WH-PHRANON')->value('pos_pin')));
    }

    #[Test]
    public function rerunning_without_env_keeps_existing_password_and_pins(): void
    {
        putenv('WMS_ADMIN_PASSWORD=Str0ng-Admin-Pass');
        putenv('WMS_POS_PINS=WH-PHRANON:482913');
        $this->seed(CompanyUserSeeder::class);

        putenv('WMS_ADMIN_PASSWORD');
        putenv('WMS_POS_PINS');
        $this->seed(CompanyUserSeeder::class);

        $this->assertTrue(Hash::check('Str0ng-Admin-Pass', User::where('email', 'admin@wms.local')->value('password')));
        $this->assertTrue(Hash::check('482913', Warehouse::where('code', 'WH-PHRANON')->value('pos_pin')));
    }
}
