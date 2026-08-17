<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion,ElectrofrioConfiguracion,Empresa};
use App\Services\{SubscriptionAccessService,TenantContext};
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ElectrofrioConfiguracionController extends Controller
{
    private const DEFAULT_SERVICE_TYPES = [
        'Diagnóstico',
        'Mantenimiento preventivo',
        'Mantenimiento correctivo',
        'Reparación',
        'Instalación',
        'Desinstalación',
        'Limpieza profunda',
        'Carga de refrigerante',
    ];

    private const DEFAULT_EQUIPMENT_TYPES = [
        'Aire acondicionado Split',
        'Aire acondicionado Piso Techo',
        'Aire acondicionado Cassette',
        'Aire acondicionado Ventana',
        'Aire acondicionado Portátil',
        'Sistema VRF / VRV',
        'Chiller',
        'Otro',
    ];

    private const DEFAULT_PAYMENT_METHODS = ['efectivo', 'qr', 'transferencia', 'tarjeta', 'otro'];

    public function show(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $config = ElectrofrioConfiguracion::query()->where('empresa_id', $empresa->id)->first();

        return response()->json(['data' => $this->payload($empresa, $config)]);
    }

    public function update(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $tenants->assertCanManage($request->user(), $empresa);

        $data = $request->validate([
            'nombre_sistema' => ['required', 'string', 'max:140'],
            'nombre_corto' => ['required', 'string', 'max:60'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'telefono' => ['nullable', 'string', 'max:40'],
            'correo' => ['nullable', 'email', 'max:180'],
            'direccion' => ['nullable', 'string', 'max:300'],
            'color_primario' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_secundario' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'moneda' => ['required', 'string', 'max:8'],
            'garantia_dias_default' => ['required', 'integer', 'min:0', 'max:3650'],
            'tipos_servicio' => ['required', 'array', 'min:1', 'max:30'],
            'tipos_servicio.*' => ['required', 'string', 'max:120'],
            'tipos_equipo' => ['required', 'array', 'min:1', 'max:30'],
            'tipos_equipo.*' => ['required', 'string', 'max:120'],
            'metodos_pago' => ['required', 'array', 'min:1', 'max:10'],
            'metodos_pago.*' => ['required', 'string', 'max:60'],
        ]);

        foreach (['nombre_sistema', 'nombre_corto', 'telefono', 'correo', 'direccion', 'moneda'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) ($data[$field] ?? ''));
                $data[$field] = $value === '' ? null : $value;
            }
        }
        $data['nombre_sistema'] ??= 'Sistema de Gestión de Servicios de Aire Acondicionado';
        $data['nombre_corto'] ??= 'Aires Acondicionados';
        $data['moneda'] = strtoupper((string) ($data['moneda'] ?? 'BOB'));
        $data['color_primario'] = strtoupper($data['color_primario']);
        $data['color_secundario'] = strtoupper($data['color_secundario']);
        $data['tipos_servicio'] = $this->normalizedList($data['tipos_servicio']);
        $data['tipos_equipo'] = $this->normalizedList($data['tipos_equipo']);
        $data['metodos_pago'] = array_map('strtolower', $this->normalizedList($data['metodos_pago']));
        $data['actualizado_por'] = $request->user()->id;

        $config = ElectrofrioConfiguracion::query()->updateOrCreate(
            ['empresa_id' => $empresa->id],
            $data
        );

        Audit::log(
            $request,
            'aires_acondicionados_configuracion_actualizada',
            $config,
            'Se actualizó la personalización del Sistema de Gestión de Servicios de Aire Acondicionado.'
        );

        return response()->json([
            'message' => 'Configuración del sistema actualizada.',
            'data' => $this->payload($empresa, $config->fresh()),
        ]);
    }

    public function uploadLogo(Request $request, TenantContext $tenants): JsonResponse
    {
        $empresa = $this->empresa($request, $tenants);
        $tenants->assertCanManage($request->user(), $empresa);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ], [
            'logo.required' => 'Selecciona un logotipo.',
            'logo.image' => 'El archivo debe ser una imagen válida.',
            'logo.mimes' => 'El logotipo debe ser JPG, PNG o WEBP.',
            'logo.max' => 'El logotipo no puede superar 3 MB.',
        ]);

        $directory = 'aires/logos/'.$empresa->id;
        Storage::disk('public')->deleteDirectory($directory);
        $path = $request->file('logo')->store($directory, 'public');
        $logoUrl = Storage::disk('public')->url($path);

        $config = ElectrofrioConfiguracion::query()->firstOrCreate(
            ['empresa_id' => $empresa->id],
            [
                'nombre_sistema' => 'Sistema de Gestión de Servicios de Aire Acondicionado',
                'nombre_corto' => 'Aires Acondicionados',
                'color_primario' => '#0B5F7A',
                'color_secundario' => '#12B8C8',
                'moneda' => 'BOB',
                'garantia_dias_default' => 0,
                'tipos_servicio' => self::DEFAULT_SERVICE_TYPES,
                'tipos_equipo' => self::DEFAULT_EQUIPMENT_TYPES,
                'metodos_pago' => self::DEFAULT_PAYMENT_METHODS,
            ]
        );
        $config->update([
            'logo_url' => $logoUrl,
            'actualizado_por' => $request->user()->id,
        ]);

        Audit::log(
            $request,
            'aires_acondicionados_logo_actualizado',
            $config,
            'Se actualizó el logotipo personalizado del sistema de aire acondicionado.'
        );

        return response()->json([
            'message' => 'Logotipo actualizado.',
            'logo_url' => $logoUrl,
            'data' => $this->payload($empresa, $config->fresh()),
        ]);
    }

    private function payload(Empresa $empresa, ?ElectrofrioConfiguracion $config): array
    {
        return [
            'empresa' => [
                'id' => $empresa->id,
                'nombre_comercial' => $empresa->nombre_comercial,
                'actividad' => $empresa->actividad,
            ],
            'nombre_sistema' => $config?->nombre_sistema ?: 'Sistema de Gestión de Servicios de Aire Acondicionado',
            'nombre_corto' => $config?->nombre_corto ?: 'Aires Acondicionados',
            'logo_url' => $config?->logo_url,
            'telefono' => $config?->telefono,
            'correo' => $config?->correo,
            'direccion' => $config?->direccion,
            'color_primario' => $config?->color_primario ?: '#0B5F7A',
            'color_secundario' => $config?->color_secundario ?: '#12B8C8',
            'moneda' => $config?->moneda ?: 'BOB',
            'garantia_dias_default' => (int) ($config?->garantia_dias_default ?? 0),
            'tipos_servicio' => $config?->tipos_servicio ?: self::DEFAULT_SERVICE_TYPES,
            'tipos_equipo' => $config?->tipos_equipo ?: self::DEFAULT_EQUIPMENT_TYPES,
            'metodos_pago' => $config?->metodos_pago ?: self::DEFAULT_PAYMENT_METHODS,
            'personalizada' => (bool) $config,
        ];
    }

    private function normalizedList(array $values): array
    {
        $items = array_values(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $values
        ), fn ($value) => $value !== ''));

        return array_values(array_unique($items));
    }

    private function empresa(Request $request, TenantContext $tenants): Empresa
    {
        $empresa = $tenants->resolve($request);
        $tenants->assertCanUse($request->user(), $empresa, 'inicio');

        if (!$request->user()->isPlatformAdmin()) {
            $app = Aplicacion::query()
                ->where('empresa_id', $empresa->id)
                ->whereHas('catalogo', fn ($query) => $query->where('clave', 'electrofrio'))
                ->with('suscripcion')
                ->latest('id')
                ->first();

            abort_unless($app, 404, 'Este negocio no tiene asignado el Sistema de Gestión de Servicios de Aire Acondicionado.');
            abort_unless((bool) $app->acceso_cliente, 403, 'El sistema todavía no fue entregado a este negocio.');
            abort_unless($app->estado === 'activo', 403, 'El acceso al sistema está suspendido.');
            app(SubscriptionAccessService::class)->assertCanUse($app);
        }

        return $empresa;
    }
}