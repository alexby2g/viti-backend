<?php

namespace Tests\Feature;

use App\Models\{InvitacionCliente, PlanViti, SolicitudAccesoViti};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SolicitudAccesoVitiRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_user_can_request_access_once(): void
    {
        $plan=PlanViti::query()->where('activo',true)->first();
        $payload=[
            'nombre'=>'Solicitante de prueba',
            'telefono'=>'700001111',
            'whatsapp'=>'700001111',
            'negocio'=>'Negocio de prueba',
            'actividad'=>'Servicios',
            'plan_codigo'=>$plan?->codigo,
            'modalidad'=>$plan ? 'mensual' : null,
            'mensaje'=>'Necesito organizar mi operación.',
        ];

        $this->postJson('/api/v1/publico/acceso/solicitar',$payload)
            ->assertCreated()
            ->assertJsonPath('data.estado','pendiente');

        $this->postJson('/api/v1/publico/acceso/solicitar',$payload)
            ->assertStatus(422)
            ->assertJsonPath('message','Ya existe una solicitud de acceso reciente para este teléfono. AGR Studio la revisará antes de crear un nuevo acceso.');

        $this->assertDatabaseCount('solicitudes_acceso_viti',1);
    }

    public function test_human_code_resolves_to_existing_registration_route(): void
    {
        $invitation=InvitacionCliente::create([
            'token'=>Str::random(64),
            'codigo'=>'VITI-TEST01',
            'estado'=>'pendiente',
            'expira_at'=>now()->addDays(7),
        ]);

        $this->getJson('/api/v1/publico/acceso/codigo/VITI-TEST01')
            ->assertOk()
            ->assertJsonPath('data.codigo','VITI-TEST01')
            ->assertJsonPath('data.token',$invitation->token)
            ->assertJsonPath('data.ruta','/registro-cliente/'.$invitation->token);
    }

    public function test_expired_code_is_rejected(): void
    {
        InvitacionCliente::create([
            'token'=>Str::random(64),
            'codigo'=>'VITI-EXPIRE',
            'estado'=>'pendiente',
            'expira_at'=>now()->subMinute(),
        ]);

        $this->getJson('/api/v1/publico/acceso/codigo/VITI-EXPIRE')->assertStatus(410);
    }
}
