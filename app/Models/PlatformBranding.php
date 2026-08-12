<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformBranding extends Model
{
    protected $table = 'configuraciones_plataforma';

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
