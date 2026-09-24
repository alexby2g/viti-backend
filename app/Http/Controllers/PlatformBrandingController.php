<?php

namespace App\Http\Controllers;

use App\Models\PlatformBranding;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PlatformBrandingController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(PlatformBranding::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $branding = PlatformBranding::current();
        $color = ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'];

        $data = $request->validate([
            'studio_name' => ['required', 'string', 'max:120'],
            'product_name' => ['required', 'string', 'max:80'],
            'product_meaning' => ['required', 'string', 'max:180'],
            'tagline' => ['required', 'string', 'max:220'],
            'primary_color' => $color,
            'secondary_color' => $color,
            'accent_color' => $color,
            'dark_color' => $color,
            'drawer_color' => $color,
            'guide_enabled' => ['required', 'boolean'],
            'guide_position' => ['required', Rule::in(['right-center', 'right-bottom'])],
        ], [
            'primary_color.regex' => 'El color principal debe estar en formato hexadecimal, por ejemplo #1565C0.',
            'secondary_color.regex' => 'El color secundario debe estar en formato hexadecimal.',
            'accent_color.regex' => 'El color de acento debe estar en formato hexadecimal.',
            'dark_color.regex' => 'El color oscuro debe estar en formato hexadecimal.',
            'drawer_color.regex' => 'El color del menú debe estar en formato hexadecimal.',
        ]);

        $branding->update($data);
        Audit::log($request, 'marca_plataforma_actualizada', $branding, 'Se actualizó la identidad visual de la plataforma.');

        return response()->json(['data' => $this->payload($branding->fresh())]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ], [
            'logo.required' => 'Selecciona un logotipo.',
            'logo.image' => 'El archivo debe ser una imagen.',
            'logo.max' => 'El logotipo no puede superar 3 MB.',
        ]);

        $branding = PlatformBranding::current();
        if ($branding->logo_path) Storage::disk('public')->delete($branding->logo_path);

        $path = $request->file('logo')->store('plataforma/branding', 'public');
        $branding->update(['logo_path' => $path]);
        Audit::log($request, 'logo_plataforma_actualizado', $branding, 'Se actualizó el logotipo principal de la plataforma.');

        return response()->json(['data' => $this->payload($branding->fresh())]);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $branding = PlatformBranding::current();
        if ($branding->logo_path) Storage::disk('public')->delete($branding->logo_path);
        $branding->update(['logo_path' => null]);
        Audit::log($request, 'logo_plataforma_eliminado', $branding, 'Se restauró el monograma predeterminado de la plataforma.');

        return response()->json(['data' => $this->payload($branding->fresh())]);
    }

    private function payload(PlatformBranding $branding): array
    {
        return [
            'id' => $branding->id,
            'studio_name' => $branding->studio_name,
            'product_name' => $branding->product_name,
            'product_meaning' => $branding->product_meaning,
            'tagline' => $branding->tagline,
            'logo_path' => $branding->logo_path,
            // Sin esto el frontend arma la URL con /storage y falla cuando el
            // disco público es R2 en lugar del disco local.
            'logo_url' => $branding->logo_url,
            'primary_color' => $branding->primary_color,
            'secondary_color' => $branding->secondary_color,
            'accent_color' => $branding->accent_color,
            'dark_color' => $branding->dark_color,
            'drawer_color' => $branding->drawer_color,
            'guide_enabled' => (bool) $branding->guide_enabled,
            'guide_position' => $branding->guide_position,
        ];
    }
}
