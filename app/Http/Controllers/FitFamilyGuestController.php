<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\FitFamilyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FitFamilyGuestController extends Controller
{
    private function tenantAndApp(Request $request, FitFamilyContext $context): array
    {
        $empresa = $context->resolve($request);
        $aplicacion = $empresa->aplicaciones()
            ->whereHas('catalogo', fn ($q) => $q->where('clave', config('fitfamily.catalog_key')))
            ->first();

        abort_unless($aplicacion, 404, 'La aplicación FitFamily no está asignada a esta empresa.');

        return [$empresa, $aplicacion];
    }

    private function token(Request $request): string
    {
        $token = trim((string) $request->header('X-FitFamily-Session'));
        return $token !== '' ? $token : Str::random(64);
    }

    private function cart(Request $request, int $empresaId, int $aplicacionId, string $token): object
    {
        $cart = DB::table('fitfamily_carritos')
            ->where('empresa_id', $empresaId)
            ->where('aplicacion_id', $aplicacionId)
            ->where('session_token', $token)
            ->where('estado', 'activo')
            ->first();

        if ($cart) {
            return $cart;
        }

        $id = DB::table('fitfamily_carritos')->insertGetId([
            'empresa_id' => $empresaId,
            'aplicacion_id' => $aplicacionId,
            'usuario_id' => null,
            'session_token' => $token,
            'estado' => 'activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('fitfamily_carritos')->find($id);
    }

    private function payload(object $cart): array
    {
        $items = DB::table('fitfamily_carrito_items as i')
            ->join('fitfamily_productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.carrito_id', $cart->id)
            ->get(['i.id', 'i.producto_id', 'p.nombre', 'p.imagen_url', 'p.precio', 'p.stock', 'i.cantidad', 'i.precio_unitario']);

        $items->transform(function ($item) {
            $item->precio_unitario = number_format((float) $item->precio_unitario, 2, '.', '');
            $item->subtotal = number_format($item->cantidad * (float) $item->precio_unitario, 2, '.', '');
            return $item;
        });

        return [
            'id' => $cart->id,
            'estado' => $cart->estado,
            'items' => $items,
            'cantidad_items' => (int) $items->sum('cantidad'),
            'total' => number_format($items->sum(fn ($item) => (float) $item->subtotal), 2, '.', ''),
        ];
    }

    public function index(Request $request, FitFamilyContext $context): JsonResponse
    {
        [$empresa, $aplicacion] = $this->tenantAndApp($request, $context);
        $token = $this->token($request);
        $cart = $this->cart($request, $empresa->id, $aplicacion->id, $token);

        return response()->json([
            'data' => $this->payload($cart),
            'session_token' => $token,
        ])->header('X-FitFamily-Session', $token);
    }

    public function add(Request $request, FitFamilyContext $context): JsonResponse
    {
        [$empresa, $aplicacion] = $this->tenantAndApp($request, $context);
        $data = $request->validate([
            'producto_id' => ['required', 'integer'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $token = $this->token($request);
        $product = DB::table('fitfamily_productos')
            ->where('empresa_id', $empresa->id)
            ->where('aplicacion_id', $aplicacion->id)
            ->where('id', $data['producto_id'])
            ->where('visible', true)
            ->where('disponible', true)
            ->first();

        abort_unless($product, 422, 'El producto no está disponible.');

        $cart = $this->cart($request, $empresa->id, $aplicacion->id, $token);
        $item = DB::table('fitfamily_carrito_items')
            ->where('carrito_id', $cart->id)
            ->where('producto_id', $product->id)
            ->first();
        $quantity = ($item->cantidad ?? 0) + $data['cantidad'];

        abort_if($quantity > $product->stock, 422, 'No hay suficiente stock.');

        if ($item) {
            DB::table('fitfamily_carrito_items')->where('id', $item->id)->update([
                'cantidad' => $quantity,
                'precio_unitario' => $product->precio,
            ]);
        } else {
            DB::table('fitfamily_carrito_items')->insert([
                'carrito_id' => $cart->id,
                'producto_id' => $product->id,
                'cantidad' => $quantity,
                'precio_unitario' => $product->precio,
            ]);
        }

        return response()->json([
            'message' => 'Producto agregado al carrito.',
            'data' => $this->payload($cart),
            'session_token' => $token,
        ], 201)->header('X-FitFamily-Session', $token);
    }

    public function update(Request $request, int $itemId, FitFamilyContext $context): JsonResponse
    {
        [$empresa, $aplicacion] = $this->tenantAndApp($request, $context);
        $data = $request->validate(['cantidad' => ['required', 'integer', 'min:1', 'max:99']]);
        $token = $this->token($request);
        $cart = $this->cart($request, $empresa->id, $aplicacion->id, $token);
        $item = DB::table('fitfamily_carrito_items as i')
            ->join('fitfamily_productos as p', 'p.id', '=', 'i.producto_id')
            ->where('i.carrito_id', $cart->id)
            ->where('i.id', $itemId)
            ->first(['i.id', 'i.cantidad', 'i.precio_unitario', 'p.stock']);

        abort_unless($item, 404, 'Artículo no encontrado.');
        abort_if($data['cantidad'] > $item->stock, 422, 'No hay suficiente stock.');

        DB::table('fitfamily_carrito_items')->where('id', $itemId)->update([
            'cantidad' => $data['cantidad'],
            'precio_unitario' => $item->precio_unitario,
        ]);

        return response()->json(['data' => $this->payload($cart), 'session_token' => $token])
            ->header('X-FitFamily-Session', $token);
    }

    public function delete(Request $request, int $itemId, FitFamilyContext $context): JsonResponse
    {
        [$empresa, $aplicacion] = $this->tenantAndApp($request, $context);
        $token = $this->token($request);
        $cart = $this->cart($request, $empresa->id, $aplicacion->id, $token);

        DB::table('fitfamily_carrito_items')
            ->where('carrito_id', $cart->id)
            ->where('id', $itemId)
            ->delete();

        return response()->json(['data' => $this->payload($cart), 'session_token' => $token])
            ->header('X-FitFamily-Session', $token);
    }

    public function checkout(Request $request, FitFamilyContext $context): JsonResponse
    {
        [$empresa, $aplicacion] = $this->tenantAndApp($request, $context);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:180'],
            'telefono' => ['required', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:190'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'direccion' => ['nullable', 'string', 'max:255'],
            'notas' => ['nullable', 'string', 'max:1000'],
            'metodo_pago' => ['required', Rule::in(['efectivo', 'transferencia', 'qr'])],
        ]);
        $token = $this->token($request);

        $order = DB::transaction(function () use ($empresa, $aplicacion, $data, $token): object {
            $cart = $this->cart(request(), $empresa->id, $aplicacion->id, $token);
            $items = DB::table('fitfamily_carrito_items')->where('carrito_id', $cart->id)->lockForUpdate()->get();
            abort_if($items->isEmpty(), 422, 'El carrito está vacío.');

            $client = Cliente::query()->where('telefono', $data['telefono'])->first();
            if (!$client && !empty($data['correo'])) {
                $client = Cliente::query()->where('correo', $data['correo'])->first();
            }
            if (!$client) {
                $client = Cliente::create([
                    'nombre' => $data['nombre'],
                    'telefono' => $data['telefono'],
                    'whatsapp' => $data['whatsapp'] ?? null,
                    'correo' => $data['correo'] ?? null,
                    'direccion' => $data['direccion'] ?? null,
                    'estado' => 'activo',
                    'canal_origen' => 'fitfamily',
                ]);
            } else {
                $client->fill([
                    'nombre' => $data['nombre'],
                    'whatsapp' => $data['whatsapp'] ?? $client->whatsapp,
                    'correo' => $data['correo'] ?? $client->correo,
                    'direccion' => $data['direccion'] ?? $client->direccion,
                ])->save();
            }

            $subtotal = 0.0;
            foreach ($items as $item) {
                $product = DB::table('fitfamily_productos')
                    ->where('empresa_id', $empresa->id)
                    ->where('aplicacion_id', $aplicacion->id)
                    ->where('id', $item->producto_id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($product && $product->visible && $product->disponible, 422, 'Un producto ya no está disponible.');
                abort_if($item->cantidad > $product->stock, 422, 'Stock insuficiente para '.$product->nombre.'.');
                $subtotal += $item->cantidad * (float) $product->precio;
            }

            $pedidoId = DB::table('fitfamily_pedidos')->insertGetId([
                'empresa_id' => $empresa->id,
                'aplicacion_id' => $aplicacion->id,
                'cliente_id' => $client->id,
                'numero' => 'FF-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'estado' => 'pendiente',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'notas' => $data['notas'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = DB::table('fitfamily_productos')->where('id', $item->producto_id)->lockForUpdate()->first();
                DB::table('fitfamily_pedido_detalles')->insert([
                    'pedido_id' => $pedidoId,
                    'producto_id' => $product->id,
                    'nombre_producto' => $product->nombre,
                    'cantidad' => $item->cantidad,
                    'precio_unitario' => $product->precio,
                    'subtotal' => $item->cantidad * (float) $product->precio,
                ]);
                $newStock = $product->stock - $item->cantidad;
                DB::table('fitfamily_productos')->where('id', $product->id)->update([
                    'stock' => $newStock,
                    'disponible' => $newStock > 0,
                    'updated_at' => now(),
                ]);
            }

            DB::table('fitfamily_pagos')->insert([
                'pedido_id' => $pedidoId,
                'metodo_pago' => $data['metodo_pago'],
                'estado' => 'pendiente',
                'monto' => $subtotal,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fitfamily_carritos')->where('id', $cart->id)->update([
                'estado' => 'convertido',
                'updated_at' => now(),
            ]);
            DB::table('fitfamily_carrito_items')->where('carrito_id', $cart->id)->delete();

            return DB::table('fitfamily_pedidos')->find($pedidoId);
        });

        return response()->json([
            'message' => 'Pedido creado correctamente.',
            'data' => $order,
            'session_token' => $token,
        ], 201)->header('X-FitFamily-Session', $token);
    }
}
