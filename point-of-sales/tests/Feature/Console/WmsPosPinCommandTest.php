<?php

namespace Tests\Feature\Console;

use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WmsPosPinCommandTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $phranon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phranon = Warehouse::create(['code' => 'WH-PHRANON', 'name' => 'พระนอน', 'type' => 'main', 'is_active' => true, 'sort_order' => 1, 'pos_pin' => Hash::make('482913')]);
        Warehouse::create(['code' => 'WH-CHOKDEE', 'name' => 'โชคดี', 'type' => 'warehouse', 'is_active' => true, 'sort_order' => 2, 'pos_pin' => Hash::make('5678')]);
    }

    #[Test]
    public function it_sets_a_four_digit_pin_typed_twice(): void
    {
        $this->artisan('wms:pos-pin', ['warehouse' => 'wh-phranon'])
            ->expectsQuestion('New 4-digit PIN for WH-PHRANON (พระนอน)', '1234')
            ->expectsQuestion('Type it again', '1234')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('1234', $this->phranon->fresh()->pos_pin));
    }

    #[Test]
    public function it_takes_the_pin_from_wms_new_pin_when_there_is_no_terminal(): void
    {
        putenv('WMS_NEW_PIN=4321');
        try {
            $this->artisan('wms:pos-pin', ['warehouse' => 'WH-PHRANON'])->assertSuccessful();
        } finally {
            putenv('WMS_NEW_PIN');
        }

        $this->assertTrue(Hash::check('4321', $this->phranon->fresh()->pos_pin));
    }

    #[Test]
    public function it_refuses_wrong_length_mismatch_and_a_pin_another_warehouse_uses(): void
    {
        $this->artisan('wms:pos-pin', ['warehouse' => 'WH-PHRANON'])
            ->expectsQuestion('New 4-digit PIN for WH-PHRANON (พระนอน)', '123456')
            ->assertFailed();

        $this->artisan('wms:pos-pin', ['warehouse' => 'WH-PHRANON'])
            ->expectsQuestion('New 4-digit PIN for WH-PHRANON (พระนอน)', '1234')
            ->expectsQuestion('Type it again', '1243')
            ->assertFailed();

        $this->artisan('wms:pos-pin', ['warehouse' => 'WH-PHRANON'])
            ->expectsQuestion('New 4-digit PIN for WH-PHRANON (พระนอน)', '5678')
            ->expectsQuestion('Type it again', '5678')
            ->expectsOutputToContain('already used by WH-CHOKDEE')
            ->assertFailed();

        $this->assertTrue(Hash::check('482913', $this->phranon->fresh()->pos_pin), 'unchanged');
    }
}
