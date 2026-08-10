<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_endpoint_includes_supplier_id_name_and_country_when_linked_to_a_supplier(): void
    {
        $country = Country::factory()->create(['name' => 'Kenya']);
        $supplier = Supplier::factory()->create(['name' => 'Kimathi Agro Exports Ltd', 'country_id' => $country->id]);
        $user = User::factory()->create(['supplier_id' => $supplier->id]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJson([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'supplier_id' => $supplier->id,
                'supplier_name' => 'Kimathi Agro Exports Ltd',
                'supplier_country' => 'Kenya',
            ],
        ]);
    }

    public function test_user_endpoint_returns_null_supplier_fields_when_not_linked_to_a_supplier(): void
    {
        $user = User::factory()->create(['supplier_id' => null]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJson([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'supplier_id' => null,
                'supplier_name' => null,
                'supplier_country' => null,
            ],
        ]);
    }
}
