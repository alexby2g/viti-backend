<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class PlatformBranding extends Model
{
    protected $table = 'configuraciones_plataforma';

    /**
     * El logotipo se resuelve igual que en Cliente/Empresa/Usuario: cuando el
     * disco público es R2 (s3), la URL apunta al bucket y no a /storage local.
     */
    protected $appends = ['logo_url'];

    protected $fillable = [
        'studio_name',
        'product_name',
        'product_meaning',
        'tagline',
        'logo_path',
        'primary_color',
        'secondary_color',
        'accent_color',
        'dark_color',
        'drawer_color',
        'guide_enabled',
        'guide_position',
    ];

    protected $casts = [
        'guide_enabled' => 'boolean',
    ];

    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        $base = rtrim((string) config('filesystems.disks.public.url'), '/');
        if ($base !== '') {
            return $base.'/'.ltrim($this->logo_path, '/');
        }

        try {
            return Storage::disk('public')->url($this->logo_path);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'studio_name' => 'AGR Studio',
            'product_name' => 'VITI',
            'product_meaning' => 'Visión Integral, Tecnología e Innovación',
            'tagline' => 'Plataforma de proyectos y soluciones digitales',
            'primary_color' => '#1565C0',
            'secondary_color' => '#43A047',
            'accent_color' => '#FB8C00',
            'dark_color' => '#071C3B',
            'drawer_color' => '#092B55',
            'guide_enabled' => true,
            'guide_position' => 'right-center',
        ]);
    }
}
