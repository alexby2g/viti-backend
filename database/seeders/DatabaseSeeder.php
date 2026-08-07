<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // No se crean empresas, clientes, proyectos ni usuarios de demostración.
        // Solo se instala el cuestionario institucional solicitado por AGR Studio.
        $this->call(CuestionarioSeeder::class);
    }
}
