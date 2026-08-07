<?php

namespace Database\Seeders;

use App\Models\Cuestionario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CuestionarioSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(database_path('data/cuestionario_v1.json')), true, 512, JSON_THROW_ON_ERROR);

        DB::transaction(function () use ($data): void {
            $cuestionario = Cuestionario::query()->updateOrCreate(
                ['nombre' => $data['nombre'], 'version' => $data['version']],
                ['descripcion' => $data['descripcion'], 'activo' => true]
            );

            $secciones = [];
            foreach ($data['secciones'] as $seccionData) {
                $seccion = $cuestionario->secciones()->updateOrCreate(
                    ['numero' => $seccionData['numero']],
                    [
                        'titulo' => $seccionData['titulo'],
                        'descripcion' => $seccionData['descripcion'] ?? null,
                        'orden' => $seccionData['orden'],
                    ]
                );
                $secciones[$seccionData['numero']] = $seccion;
            }

            foreach ($data['preguntas'] as $preguntaData) {
                $seccion = $secciones[$preguntaData['seccion']];
                $seccion->preguntas()->updateOrCreate(
                    ['numero' => $preguntaData['numero']],
                    [
                        'pregunta' => $preguntaData['pregunta'],
                        'tipo' => $preguntaData['tipo'],
                        'opciones' => $preguntaData['opciones'],
                        'ayuda' => $preguntaData['ayuda'],
                        'obligatoria' => $preguntaData['obligatoria'],
                        'orden' => $preguntaData['numero'],
                    ]
                );
            }
        });
    }
}
