<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Reporte general · {{ $empresa->nombre_comercial }}</title>
<style>
body{font-family:DejaVu Sans,sans-serif;color:#1f2937;font-size:9px;margin:22px}.header{border-bottom:2px solid #0d315b;padding-bottom:10px;margin-bottom:14px}.brand{font-size:18px;font-weight:700;color:#0d315b}.muted{color:#64748b}.stats{width:100%;border-collapse:separate;border-spacing:8px 0;margin:10px -8px 16px}.stat{border:1px solid #dbe3ec;border-radius:8px;padding:9px}.stat strong{display:block;font-size:15px;color:#0d315b}.section{font-size:12px;font-weight:700;color:#0d315b;margin:16px 0 7px}.table{width:100%;border-collapse:collapse}.table th{background:#edf3f9;text-align:left;padding:6px;border-bottom:1px solid #cbd5e1}.table td{padding:6px;border-bottom:1px solid #e5e7eb;vertical-align:top}.right{text-align:right}.badge{font-weight:700}.footer{margin-top:20px;border-top:1px solid #dbe3ec;padding-top:8px;color:#64748b;font-size:8px}
</style>
</head>
<body>
<div class="header"><div class="brand">{{ $empresa->nombre_comercial }}</div><div>Reporte operativo de Servicio Técnico VITI</div><div class="muted">Periodo: {{ $desde ?: 'Inicio de registros' }} a {{ $hasta ?: 'Fecha actual' }} · Generado {{ now()->format('d/m/Y H:i') }}</div></div>

<table class="stats"><tr>
<td><div class="stat"><strong>{{ $estadisticas['ordenes'] }}</strong>Órdenes</div></td>
<td><div class="stat"><strong>{{ $estadisticas['entregadas'] }}</strong>Entregadas</div></td>
<td><div class="stat"><strong>{{ $estadisticas['sin_reparacion'] }}</strong>Sin reparación</div></td>
<td><div class="stat"><strong>{{ number_format($estadisticas['facturado'],2) }} Bs</strong>Total registrado</div></td>
<td><div class="stat"><strong>{{ number_format($estadisticas['cobrado'],2) }} Bs</strong>Cobrado</div></td>
<td><div class="stat"><strong>{{ number_format($estadisticas['saldo'],2) }} Bs</strong>Saldo</div></td>
</tr></table>

<div class="section">Órdenes del periodo</div>
<table class="table"><thead><tr><th>Orden</th><th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Técnico</th><th>Estado</th><th class="right">Total</th><th class="right">Pagado</th><th class="right">Saldo</th></tr></thead><tbody>
@forelse($ordenes as $orden)
<tr><td>{{ $orden->codigo }}</td><td>{{ $orden->fecha_recepcion }}</td><td>{{ $orden->cliente_nombre }}</td><td>{{ collect([$orden->equipo_tipo,$orden->equipo_marca,$orden->equipo_modelo])->filter()->implode(' · ') ?: '—' }}</td><td>{{ $orden->tecnico_nombre ?: '—' }}</td><td class="badge">{{ $estados[$orden->estado] ?? ucfirst(str_replace('_',' ',$orden->estado)) }}</td><td class="right">{{ number_format((float)$orden->total,2) }}</td><td class="right">{{ number_format((float)$orden->pagado,2) }}</td><td class="right">{{ number_format((float)$orden->saldo,2) }}</td></tr>
@empty<tr><td colspan="9">No hay órdenes en el periodo seleccionado.</td></tr>@endforelse
</tbody></table>

<div class="section">Pagos del periodo</div>
<table class="table"><thead><tr><th>Fecha</th><th>Orden</th><th>Cliente</th><th>Método</th><th>Referencia</th><th class="right">Monto</th></tr></thead><tbody>
@forelse($pagos as $pago)<tr><td>{{ $pago->pagado_at }}</td><td>{{ $pago->orden_codigo }}</td><td>{{ $pago->cliente_nombre }}</td><td>{{ ucfirst($pago->metodo) }}</td><td>{{ $pago->referencia ?: '—' }}</td><td class="right">{{ number_format((float)$pago->monto,2) }} Bs</td></tr>@empty<tr><td colspan="6">No hay pagos registrados en el periodo.</td></tr>@endforelse
</tbody></table>

<div class="footer">Reporte generado por VITI. Los datos corresponden al negocio seleccionado y se mantienen aislados por empresa.</div>
</body>
</html>