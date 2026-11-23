<?php

namespace Tests\Feature\KGarden;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard's "K Garden apps" button: it links to ERPNext's per-person app list, and only
 * when K Garden central access is on.
 */
class KGardenAppsButtonTest extends TestCase
{
    use RefreshDatabase;

    /** Someone ERPNext manages: with central access on, nobody else stays signed in. */
    private function managed(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['kgarden_managed' => true, 'employee_code' => 'EMP1'])->save();

        return $user;
    }

    #[Test]
    public function signed_in_people_get_the_link_when_central_access_is_on(): void
    {
        config(['kgarden.enabled' => true, 'kgarden.erp_url' => 'https://erp.example.test']);

        $this->actingAs($this->managed())
            ->get(route('dashboard.access'))
            ->assertInertia(fn ($page) => $page->where('kgardenAppsUrl', 'https://erp.example.test/kgarden-apps'));
    }

    #[Test]
    public function no_link_while_central_access_is_off(): void
    {
        config(['kgarden.enabled' => false, 'kgarden.erp_url' => 'https://erp.example.test']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard.access'))
            ->assertInertia(fn ($page) => $page->where('kgardenAppsUrl', null));
    }

    #[Test]
    public function no_link_without_an_erpnext_address(): void
    {
        config(['kgarden.enabled' => true, 'kgarden.erp_url' => '']);

        $this->actingAs($this->managed())
            ->get(route('dashboard.access'))
            ->assertInertia(fn ($page) => $page->where('kgardenAppsUrl', null));
    }
}
