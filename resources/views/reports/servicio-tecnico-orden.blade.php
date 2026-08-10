<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $orden->codigo }} · Orden de servicio</title>
<style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:11px;line-height:1.45;margin:24px}.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #0d315b;padding-bottom:12px;margin-bottom:18px}.brand{font-size:20px;font-weight:700;color:#0d315b}.muted{color:#64748b}.badge{display:inline-block;padding:4px 8px;border-radius:10px;background:#e8eef6;color:#0d315b;font-weight:700}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 18px}.box{border:1px solid #dbe3ec;border-radius:8px;padding:10px;margin-bottom:12px}.label{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:3px}.value{font-weight:600}.title{font-size:14px;font-weight:700;color:#0d315b;margin:18px 0 8px}.money{width:100%;border-collapse:collapse}.money td{padding:5px 0;border-bottom:1px solid #edf1f5}.money td:last-child{text-align:right;font-weight:700}.photos{margin-top:8px}.photo{display:inline-block;border:1px solid #dbe3ec;border-radius:6px;padding:6px;margin:0 6px 6px 0}.footer{margin-top:26px;padding-top:10px;border-top:1px solid #dbe3ec;color:#64748b;font-size:9px}.no-break{page-break-inside:avoid}
</style>
</head>
<body>
<div class="header">
  <div><div class="brand">{{ $empresa->nombre_comercial }}</div><div class="muted">Servicio Técnico VITI · Orden de servicio</div></div>
  <div style="text-align:right"><div style="font-size:18px;font-weight:700">{{ $orden->codigo }}</div><span class="badge">{{ $estado }}</span></div>
</div>

<div class="grid box no-break">
  <div><div class="label">Cliente</div><div class="value">{{ $orden->cliente_nombre }}</div></div>
  <div><div class="label">Teléfono</div><div class="value">{{ $orden->cliente_telefono ?: '—' }}</div></div>
  <div><div class="label">Computadora / equipo</div><div class="value">{{ collect([$orden->equipo_tipo,$orden->equipo_marca,$orden->equipo_modelo])->filter()->implode(' · ') ?: 'Sin equipo asociado' }}</div></div>
  <div><div class="label">Serie</div><div class="value">{{ $orden->equipo_serie ?: '—' }}</div></div>
  <div><div class="label">Técnico</div><div class="value">{{ $orden->tecnico_nombre ?: 'Sin asignar' }}</div></div>
  <div><div class="label">Recepción</div><div class="value">{{ $orden->fecha_recepcion }}</div></div>
  <div><div class="label">Programación</div><div class="value">{{ $orden->fecha_programada ?: '—' }} {{ $orden->hora_programada ? substr($orden->hora_programada,0,5) : '' }}</div></div>
  <div><div class="label">Prioridad</div><div class="value">{{ ucfirst($orden->prioridad) }}</div></div>
</div>

<div class="title">Detalle técnico</div>
<div class="box"><div class="label">Problema reportado</div><div>{{ $orden->problema_reportado }}</div></div>
@if($orden->diagnostico)<div class="box"><div class="label">Diagnóstico</div><div>{{ $orden->diagnostico }}</div></div>@endif
@if($orden->propuesta)<div class="box"><div class="label">Propuesta</div><div>{{ $orden->propuesta }}</div></div>@endif
@if($orden->motivo_rechazo)<div class="box"><div class="label">Motivo de cierre sin reparación</div><div>{{ $orden->motivo_rechazo }}</div></div>@endif
@if($orden->trabajo_realizado)<div class="box"><div class="label">Trabajo realizado</div><div>{{ $orden->trabajo_realizado }}</div></div>@endif
@if($orden->recomendaciones)<div class="box"><div class="label">Recomendaciones</div><div>{{ $orden->recomendaciones }}</div></div>@endif

<div class="title">Importes</div>
<div class="box no-break"><table class="money"><tr><td>Costo del servicio</td><td>{{ number_format((float)$orden->costo_servicio,2) }} Bs</td></tr><tr><td>Descuento</td><td>- {{ number_format((float)$orden->descuento,2) }} Bs</td></tr><tr><td>Total</td><td>{{ number_format((float)$orden->total,2) }} Bs</td></tr><tr><td>Pagado</td><td>{{ number_format((float)$orden->pagado,2) }} Bs</td></tr><tr><td>Saldo</td><td>{{ number_format((float)$orden->saldo,2) }} Bs</td></tr></table></div>

@if($orden->garantia_fin)
<div class="title">Garantía</div>
<div class="box no-break"><div><strong>{{ $orden->garantia_dias }} días</strong> · {{ $orden->garantia_inicio }} al {{ $orden->garantia_fin }}</div>@if($orden->condiciones_garantia)<div style="margin-top:6px">{{ $orden->condiciones_garantia }}</div>@endif</div>
@endif

@if($orden->pagos->count())
<div class="title">Pagos registrados</div>
<div class="box no-break"><table class="money">@foreach($orden->pagos as $pago)<tr><td>{{ $pago->pagado_at }} · {{ ucfirst($pago->metodo) }}{{ $pago->referencia ? ' · '.$pago->referencia : '' }}</td><td>{{ number_format((float)$pago->monto,2) }} Bs</td></tr>@endforeach</table></div>
@endif

<div class="title">Evidencias</div>
<div class="box"><div>{{ $orden->evidencias->count() }} fotografía(s) privada(s) registradas en la orden.</div>@if($orden->evidencias->count())<div class="photos">@foreach($orden->evidencias as $evidencia)<span class="photo">{{ ucfirst($evidencia->etapa) }} · {{ $evidencia->nombre_original }}</span>@endforeach</div>@endif</div>

<div class="footer">Documento generado por VITI para {{ $empresa->nombre_comercial }}. Las fotografías se mantienen en almacenamiento privado y no se incrustan en este reporte para preservar su acceso controlado.</div>
</body>
</html>