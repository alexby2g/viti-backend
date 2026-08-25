<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\Usuario;
use App\Support\Code;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VitiPlatformSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->removePeluqueriaDemo();
            $this->provisionElectroFrio();
        });
    }

    private function removePeluqueriaDemo(): void
    {
        Empresa::withTrashed()
            ->where('nombre_comercial', 'Salón Bella VITI Demo')
            ->get()
            ->each(function (Empresa $empresa): void {
                DB::table('empresa_usuario')
                    ->where('empresa_id', $empresa->id)
                    ->delete();

                DB::table('aplicacion_usuario')
                    ->whereIn('aplicacion_id', function ($query) use ($empresa): void {
                        $query->select('id')
                            ->from('aplicaciones')
                            ->where('empresa_id', $empresa->id);
                    })
                    ->delete();

                $empresa->delete();
            });

        Usuario::query()
            ->where('usuario', 'peluqueria.demo')
            ->update(['estado' => 'inactivo']);
    }

    private function provisionElectroFrio(): void
    {
        $empresa = Empresa::withTrashed()
            ->where('nombre_comercial', 'Electro Frío')
            ->first();

        if (!$empresa) {
            Empresa::query()->create([
                'cliente_id' => null,
                'codigo' => Code::next('empresas', 'EMP'),
                'nombre_comercial' => 'Electro Frío',
                'razon_social' => 'Electro Frío',
                'actividad' => 'Servicios técnicos de aire acondicionado y refrigeración.',
                'estado' => 'activo',
                'observaciones' => 'Empresa creada por VITI para posterior asignación de cliente y usuario propietario.',
            ]);
            return;
        }

        // Re-running the platform seeder must never remove a real owner,
        // client association, or business users assigned after initial setup.
        if ($empresa->trashed()) {
            $empresa->restore();
        }

        if ($empresa->estado !== 'activo') {
            $empresa->update(['estado' => 'activo']);
        }

        if (!$empresa->observaciones) {
            $empresa->update([
                'observaciones' => 'Empresa creada por VITI para posterior asignación de cliente y usuario propietario.',
            ]);
        }
    }
}
