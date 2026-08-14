<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>{{ $orden->codigo }} · Electrofrío</title>
<style>
body{font-family:DejaVu Sans,sans-serif;color:#183247;font-size:10.5px;line-height:1.45;margin:22px}.header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0b5f7a;padding-bottom:12px;margin-bottom:16px}.brand{font-size:21px;font-weight:700;color:#0b5f7a}.subtitle{color:#647b8b}.code{font-size:17px;font-weight:700;text-align:right}.badge{display:inline-block;margin-top:4px;padding:4px 9px;border-radius:10px;background:#e5f3f7;color:#0b5f7a;font-weight:700}.title{font-size:13px;font-weight:700;color:#0b5f7a;margin:16px 0 7px}.box{border:1px solid #d7e4eb;border-radius:8px;padding:9px;margin-bottom:10px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:8px 16px}.label{font-size:8.5px;text-transform:uppercase;letter-spacing:.04em;color:#6d8291;margin-bottom:2px}.value{font-weight:600}.money{width:100%;border-collapse:collapse}.money td{padding:4px 0;border-bottom:1px solid #eef3f6}.money td:last-child{text-align:right;font-weight:700}.tag{display:inline-block;border:1px solid #d7e4eb;border-radius:5px;padding:4px 6px;margin:0 5px 5px 0}.footer{margin-top:22px;border-top:1px solid #d7e4eb;padding-top:8px;color:#718795;font-size:8.5px}.no-break{page-break-inside:avoid}.strong{font-weight:700;color:#0b5f7a}
</style>
</head>
<body>
<div class="header">
  <div>
    <div class="brand">{{ $empresa->nombre_comercial }}</div>
    <div class="subtitle">Electrofrío · Orden de servicio generada por VITI</div>
  </div>
  <div class="code">
    {{ $orden->codigo }}<br>
    <span class="badge">{{ ucfirst($orden->etapa) }}</span>
  </div>
</div>

<div class="grid box no-break">
  <div><div class="label">Cliente</div><div class="value">{{ $orden->cliente_nombre }}</div></div>
  <div><div class="label">Teléfono</div><div class="value">{{ $orden->cliente_telefono ?: '—' }}</div></div>
  <div><div class="label">Equipo</div><div class="value">{{ collect([$orden->equipo_tipo,$orden->equipo_marca,$orden->equipo_modelo])->filter()->implode(' · ') ?: 'Sin equipo asociado' }}</div></div>
  <div><div class="label">Serie / capacidad</div><div class="value">{{ collect([$orden->equipo_serie,$orden->equipo_capacidad])->filter()->implode(' · ') ?: '—' }}</div></div>
  <div><div class="label">Tipo de servicio</div><div class="value">{{ $orden->tipo_servicio ?: 'Sin clasificar' }}</div></div>
  <div><div class="label">Técnico</div><div class="value">{{ $orden->tecnico_nombre ?: 'Sin asignar' }}</div></div>
  <div><div class="label">Cita</div><div class="value">{{ $orden->fecha_cita }} {{ $orden->hora_cita ? substr($orden->hora_cita,0,5) : '' }}</div></div>
  <div><div class="label">Prioridad</div><div class="value">{{ ucfirst($orden->prioridad) }}</div></div>
  <div style="grid-column:1/-1"><div class="label">Dirección del servicio</div><div class="value">{{ $orden->direccion_servicio }}{{ $orden->referencia_ubicacion ? ' · '.$orden->referencia_ubicacion : '' }}</div></div>
</div>

@if($ficha)
<div class="title">Ficha técnica del equipo</div>
<div class="grid box no-break">
  <div><div class="label">Gas refrigerante</div><div class="value">{{ $ficha->gas_refrigerante ?: '—' }}</div></div>
  <div><div class="label">Voltaje</div><div class="value">{{ $ficha->voltaje ?: '—' }}</div></div>
  <div><div class="label">Amperaje nominal</div><div class="value">{{ $ficha->amperaje_nominal !== null ? number_format((float)$ficha->amperaje_nominal,2).' A' : '—' }}</div></div>
  <div><div class="label">Presión succión / descarga</div><div class="value">{{ $ficha->presion_succion_psi !== null ? number_format((float)$ficha->presion_succion_psi,2).' PSI' : '—' }} / {{ $ficha->presion_descarga_psi !== null ? number_format((float)$ficha->presion_descarga_psi,2).' PSI' : '—' }}</div></div>
  @if($ficha->observaciones_tecnicas)<div style="grid-column:1/-1"><div class="label">Observaciones técnicas</div><div>{{ $ficha->observaciones_tecnicas }}</div></div>@endif
</div>
@endif

<div class="title">Detalle del trabajo</div>
<div class="box"><div class="label">Problema reportado</div><div>{{ $orden->problema_reportado }}</div></div>
@if($orden->diagnostico)<div class="box"><div class="label">Diagnóstico</div><div>{{ $orden->diagnostico }}</div></div>@endif
@if($orden->propuesta)<div class="box"><div class="label">Propuesta</div><div>{{ $orden->propuesta }}</div></div>@endif
@if($orden->motivo_rechazo)<div class="box"><div class="label">Motivo de no aceptación</div><div>{{ $orden->motivo_rechazo }}</div></div>@endif
@if($orden->trabajo_realizado)<div class="box"><div class="label">Trabajo realizado</div><div>{{ $orden->trabajo_realizado }}</div></div>@endif
@if($orden->recomendaciones)<div class="box"><div class="label">Recomendaciones</div><div>{{ $orden->recomendaciones }}</div></div>@endif

@if($orden->materiales->count())
<div class="title">Materiales utilizados</div>
<div class="box no-break"><table class="money">@foreach($orden->materiales as $material)<tr><td>{{ $material->material_nombre }} · {{ number_format((float)$material->cantidad,2) }} {{ $material->material_unidad }}</td><td>{{ number_format((float)$material->subtotal,2) }} Bs</td></tr>@endforeach</table></div>
@endif

<div class="title">Importes</div>
<div class="box no-break"><table class="money">
<tr><td>Mano de obra</td><td>{{ number_format((float)$orden->costo_mano_obra,2) }} Bs</td></tr>
<tr><td>Materiales</td><td>{{ number_format((float)$orden->costo_materiales,2) }} Bs</td></tr>
<tr><td>Descuento</td><td>- {{ number_format((float)$orden->descuento,2) }} Bs</td></tr>
<tr><td class="strong">Total</td><td class="strong">{{ number_format((float)$orden->total,2) }} Bs</td></tr>
<tr><td>Pagado</td><td>{{ number_format((float)$orden->pagado,2) }} Bs</td></tr>
<tr><td>Saldo</td><td>{{ number_format((float)$orden->saldo,2) }} Bs</td></tr>
</table></div>

@if($orden->pagos->count())
<div class="title">Pagos registrados</div>
<div class="box no-break"><table class="money">@foreach($orden->pagos as $pago)<tr><td>{{ $pago->pagado_at }} · {{ ucfirst($pago->metodo) }}{{ $pago->referencia ? ' · '.$pago->referencia : '' }}</td><td>{{ number_format((float)$pago->monto,2) }} Bs</td></tr>@endforeach</table></div>
@endif

@if($orden->garantia_fin)
<div class="title">Garantía</div>
<div class="box no-break"><div><strong>{{ $orden->garantia_dias }} días</strong> · {{ $orden->garantia_inicio }} al {{ $orden->garantia_fin }}</div>@if($orden->condiciones_garantia)<div style="margin-top:5px">{{ $orden->condiciones_garantia }}</div>@endif</div>
@endif

<div class="title">Evidencias privadas</div>
<div class="box"><div>{{ $orden->evidencias->count() }} archivo(s) asociado(s) a la orden.</div>@if($orden->evidencias->count())<div style="margin-top:7px">@foreach($orden->evidencias as $evidencia)<span class="tag">{{ ucfirst($evidencia->categoria) }} · {{ $evidencia->nombre_original }}</span>@endforeach</div>@endif</div>

<div class="footer">Documento generado por VITI para {{ $empresa->nombre_comercial }}. Las fotografías, comprobantes y documentos adjuntos permanecen en almacenamiento privado y requieren autorización para su descarga.</div>
</body>
</html>
