<?php

namespace Database\Seeders;

use App\Models\Aplicacion;
use App\Models\CatalogoAplicacion;
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
            $this->provisionFitFamily();
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

    private function provisionFitFamily(): void
    {
        $catalogo = CatalogoAplicacion::query()->updateOrCreate(
            ['clave' => 'fitfamily'],
            [
                'nombre' => 'FitFamily',
                'descripcion' => 'Comercio y gestión de productos/alimentos',
                'tipo' => 'comercial',
                'ruta_base' => '/mi-apps/fitfamily/inicio',
                'activo' => true,
                'solicitable' => true,
                'orden' => 60,
            ]
        );

        $empresa = Empresa::withTrashed()
            ->where(function ($query): void {
                $query->where('nombre_comercial', 'FitFamily')
                    ->orWhere('codigo', 'EMP-FITFAMILY');
            })
            ->first();

        if (!$empresa) {
            $empresa = Empresa::query()->create([
                'cliente_id' => null,
                'codigo' => 'EMP-FITFAMILY',
                'nombre_comercial' => 'FitFamily',
                'razon_social' => 'FitFamily',
                'actividad' => 'Comercio de alimentos, productos fitness y nutrición.',
                'estado' => 'activo',
                'observaciones' => 'Tenant comercial provisionado por VITI para la aplicación FitFamily.',
            ]);
        } else {
            if ($empresa->trashed()) {
                $empresa->restore();
            }
            $empresa->update(['estado' => 'activo']);
        }

        Aplicacion::query()->updateOrCreate(
            ['slug' => 'fitfamily'],
            [
                'empresa_id' => $empresa->id,
                'proyecto_id' => null,
                'catalogo_aplicacion_id' => $catalogo->id,
                'nombre' => 'FitFamily',
                'version' => '1.0.0',
                'entorno' => 'produccion',
                'estado' => 'publicado',
                'acceso_cliente' => true,
                'url' => null,
                'url_administracion' => null,
                'notas' => 'Aplicación FitFamily unificada dentro de VITI.',
            ]
        );
    }
}
