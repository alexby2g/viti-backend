<!doctype html><html><head><meta charset="utf-8">@include('reports._style')</head><body>
<div class="brand"><h1>VITI</h1><div class="muted">Reporte de clientes y empresas · {{ now()->format('d/m/Y H:i') }}</div></div>
<table class="grid"><thead><tr><th>Cliente</th><th>Teléfono</th><th>WhatsApp</th><th>Empresa(s)</th><th>Ciudad</th><th>Solicitudes</th><th>Proyectos</th><th>Estado</th></tr></thead><tbody>
@forelse($clientes as $cliente)<tr><td>{{ $cliente->nombre }}</td><td>{{ $cliente->telefono }}</td><td>{{ $cliente->whatsapp }}</td><td>{{ $cliente->empresas->pluck('nombre_comercial')->join(', ') ?: 'Sin empresa' }}</td><td>{{ $cliente->ciudad }}</td><td>{{ $cliente->solicitudes_count }}</td><td>{{ $cliente->proyectos_count }}</td><td>{{ $cliente->estado }}</td></tr>@empty<tr><td colspan="8">No existen clientes registrados.</td></tr>@endforelse
</tbody></table><div class="footer">VITI · Gestión de clientes y soluciones digitales</div></body></html>
