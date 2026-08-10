<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CORRECT_URL = 'https://electrofrio-frontend-git-agent-f-45038d-alexby2g-6957s-projects.vercel.app';

    private const PREVIOUS_URL = 'https://electrofrio-frontend.vercel.app';

    public function up(): void
    {
        DB::table('aplicaciones')
            ->where('slug', 'electrofrio-viti')
            ->update([
                'version' => '13.3',
                'url' => self::CORRECT_URL,
                'url_administracion' => self::CORRECT_URL,
                'notas' => 'Electrofrío V13.3 integrado desde la rama agent/fix-whatsapp-code-flow. Los datos operativos comienzan vacíos.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('aplicaciones')
            ->where('slug', 'electrofrio-viti')
            ->where('url', self::CORRECT_URL)
            ->update([
                'version' => '1.0',
                'url' => self::PREVIOUS_URL,
                'url_administracion' => self::PREVIOUS_URL,
                'notas' => 'Integración propia de Electrofrío. Los datos operativos comienzan vacíos.',
                'updated_at' => now(),
            ]);
    }
};
