<?php

namespace App\Http\Controllers;

use App\Services\FitFamilyContext;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FitFamilyOrderController extends Controller
{
    public function track(string $numero, FitFamilyContext $context): JsonResponse
    {
        $empresa = $context->resolve(request());

        $order = DB::table('fitfamily_pedidos')
            ->where('empresa_id', $empresa->id)
            ->where('numero', $numero)
            ->first();

        abort_unless($order, 404, 'Pedido no encontrado.');

        $details = DB::table('fitfamily_pedido_detalles')
            ->where('pedido_id', $order->id)
            ->get(['nombre_producto', 'cantidad', 'precio_unitario', 'subtotal']);

        return response()->json([
            'data' => [
                'numero' => $order->numero,
                'estado' => $order->estado,
                'subtotal' => $order->subtotal,
                'total' => $order->total,
                'notas' => $order->notas,
                'created_at' => $order->created_at,
                'detalles' => $details,
            ],
        ]);
    }

    public function updateStatus(Request $request, int $id, FitFamilyContext $context): JsonResponse
    {
        $empresa = $context->resolve($request);
        $context->assertAdmin($request, $empresa);

        $data = $request->validate([
            'estado' => [
                'required',
                Rule::in(['pendiente', 'confirmado', 'preparando', 'listo', 'entregado', 'cancelado']),
            ],
        ]);

        $order = DB::table('fitfamily_pedidos')
            ->where('empresa_id', $empresa->id)
            ->where('id', $id)
            ->first();

        abort_unless($order, 404, 'Pedido no encontrado.');

        $allowed = [
            'pendiente' => ['confirmado', 'cancelado'],
            'confirmado' => ['preparando', 'cancelado'],
            'preparando' => ['listo', 'cancelado'],
            'listo' => ['entregado', 'cancelado'],
            'entregado' => [],
            'cancelado' => [],
        ];

        $newState = $data['estado'];
        abort_if($newState === $order->estado, 422, 'El pedido ya tiene ese estado.');
        abort_unless(
            in_array($newState, $allowed[$order->estado] ?? [], true),
            422,
            "No se puede pasar un pedido de {$order->estado} a {$newState}."
        );

        DB::transaction(function () use ($order, $newState, $request): void {
            if ($newState === 'cancelado') {
                $details = DB::table('fitfamily_pedido_detalles')
                    ->where('pedido_id', $order->id)
                    ->get(['producto_id', 'cantidad']);

                foreach ($details as $detail) {
                    $product = DB::table('fitfamily_productos')
                        ->lockForUpdate()
                        ->find($detail->producto_id);

                    if (!$product) {
                        continue;
                    }

                    $newStock = $product->stock + $detail->cantidad;

                    DB::table('fitfamily_productos')
                        ->where('id', $product->id)
                        ->update([
                            'stock' => $newStock,
                            'disponible' => $newStock > 0,
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::table('fitfamily_pedidos')
                ->where('id', $order->id)
                ->update([
                    'estado' => $newState,
                    'updated_at' => now(),
                ]);

            Audit::log(
                $request,
                'fitfamily_pedido_estado_actualizado',
                null,
                'Estado de pedido FitFamily actualizado.',
                [
                    'pedido_id' => $order->id,
                    'numero' => $order->numero,
                    'estado_anterior' => $order->estado,
                    'estado_nuevo' => $newState,
                ],
            );
        });

        return response()->json([
            'message' => 'Estado del pedido actualizado.',
            'data' => DB::table('fitfamily_pedidos')->find($order->id),
        ]);
    }
}
