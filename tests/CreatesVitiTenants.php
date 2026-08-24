<?php

namespace Tests;

use App\Models\{Aplicacion,CatalogoAplicacion,Cliente,ElectrofrioCliente,Empresa,PlanViti,Usuario};

trait CreatesVitiTenants
{
    protected function createTenant(string $suffix, string $role = 'propietario', ?array $permissions = null): array
    {
        $plan = PlanViti::query()->where('codigo', 'basico-1800')->firstOrFail();
        $catalog = CatalogoAplicacion::query()->where('clave', 'electrofrio')->firstOrFail();

        $client = Cliente::create([
            'nombre' => 'Cliente '.$suffix,
            'telefono' => '7'.substr(str_pad((string) abs(crc32('client-phone-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'correo' => 'cliente_'.strtolower($suffix).'@example.test',
            'estado' => 'activo',
        ]);

        $company = Empresa::create([
            'cliente_id' => $client->id,
            'plan_viti_id' => $plan->id,
            'codigo' => 'TEST-'.$suffix,
            'nombre_comercial' => 'Negocio '.$suffix,
            'estado' => 'activo',
        ]);

        $app = Aplicacion::create([
            'empresa_id' => $company->id,
            'catalogo_aplicacion_id' => $catalog->id,
            'nombre' => 'Electrofrío '.$suffix,
            'slug' => 'electrofrio-'.strtolower($suffix),
            'estado' => 'activo',
            'entorno' => 'produccion',
            'acceso_cliente' => true,
            'entregado_at' => now(),
        ]);

        $user = Usuario::create([
            'cliente_id' => $client->id,
            'nombre' => 'Usuario '.$suffix,
            'usuario' => 'usuario_'.strtolower($suffix),
            'documento' => '9'.str_pad((string) abs(crc32($suffix)), 9, '0', STR_PAD_LEFT),
            'telefono' => $client->telefono,
            'correo' => 'usuario_'.strtolower($suffix).'@example.test',
            'password' => 'Prueba1234',
            'rol' => 'cliente',
            'estado' => 'activo',
        ]);

        $company->usuarios()->attach($user->id, [
            'rol_negocio' => $role,
            'permisos' => $permissions === null ? null : json_encode($permissions),
            'activo' => true,
        ]);

        return compact('plan', 'catalog', 'client', 'company', 'app', 'user');
    }

    protected function createFinalCustomer(Empresa $company, string $suffix): array
    {
        $customer = ElectrofrioCliente::create([
            'empresa_id' => $company->id,
            'nombre' => 'Cliente final '.$suffix,
            'telefono' => '6'.substr(str_pad((string) abs(crc32('customer-'.$suffix)), 8, '0', STR_PAD_LEFT), 0, 8),
            'activo' => true,
        ]);
        $user = Usuario::create([
            'electrofrio_cliente_id' => $customer->id,
            'nombre' => $customer->nombre,
            'usuario' => 'final_'.strtolower($suffix),
            'documento' => '8'.str_pad((string) abs(crc32('ci-'.$suffix)), 9, '0', STR_PAD_LEFT),
            'telefono' => $customer->telefono,
            'password' => 'Prueba1234',
            'rol' => 'cliente_negocio',
            'estado' => 'activo',
        ]);

        return compact('customer', 'user');
    }
}
