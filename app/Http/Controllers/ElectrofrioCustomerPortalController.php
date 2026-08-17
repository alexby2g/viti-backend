<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ElectrofrioCustomerPortalController extends Controller
{
    public function resumen(Request $request): JsonResponse
    {
        $client = $request->user()->electrofrioCliente()->with(['empresa:id,nombre_comercial,telefono,whatsapp','equipos'])->firstOrFail();
        $orders = DB::table('electrofrio_ordenes as o')
            ->leftJoin('electrofrio_equipos as e','e.id','=','o.equipo_id')
            ->where('o.empresa_id',$client->empresa_id)->where('o.cliente_id',$client->id)
            ->select('o.id','o.codigo','o.fecha_cita','o.hora_cita','o.problema_reportado','o.diagnostico','o.propuesta','o.etapa','o.decision_cliente','o.trabajo_realizado','o.recomendaciones','o.created_at','e.tipo as equipo_tipo','e.marca as equipo_marca','e.modelo as equipo_modelo')
            ->latest('o.id')->limit(50)->get();

        $config = DB::table('electrofrio_configuraciones')->where('empresa_id', $client->empresa_id)->first();
        $system = [
            'nombre_sistema' => $config?->nombre_sistema ?: 'Sistema de Gestión de Servicios de Aire Acondicionado',
            'nombre_corto' => $config?->nombre_corto ?: 'Aires Acondicionados',
            'logo_url' => $config?->logo_url,
            'color_primario' => $config?->color_primario ?: '#0B5F7A',
            'color_secundario' => $config?->color_secundario ?: '#12B8C8',
        ];

        return response()->json(['data'=>['cliente'=>$client,'sistema'=>$system,'ordenes'=>$orders]]);
    }
}
