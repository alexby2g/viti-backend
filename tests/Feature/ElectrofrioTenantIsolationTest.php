<?php

namespace Tests\Feature;

use App\Models\ElectrofrioCliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\{CreatesVitiTenants,TestCase};

class ElectrofrioTenantIsolationTest extends TestCase
{
    use CreatesVitiTenants, RefreshDatabase;

    public function test_business_user_only_reads_the_selected_authorized_company(): void
    {
        $first = $this->createTenant('AISLAMIENTO-A');
        $second = $this->createTenant('AISLAMIENTO-B');
        ElectrofrioCliente::create(['empresa_id'=>$first['company']->id,'nombre'=>'Cliente propio','activo'=>true]);
        ElectrofrioCliente::create(['empresa_id'=>$second['company']->id,'nombre'=>'Cliente ajeno','activo'=>true]);

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/clientes', ['X-VITI-Empresa'=>(string)$first['company']->id])
            ->assertOk()
            ->assertJsonFragment(['nombre'=>'Cliente propio'])
            ->assertJsonMissing(['nombre'=>'Cliente ajeno']);

        $this->actingAs($first['user'])
            ->getJson('/api/v1/mi/apps/electrofrio/clientes', ['X-VITI-Empresa'=>(string)$second['company']->id])
            ->assertForbidden();
    }
}
