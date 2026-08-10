<!doctype html><html><head><meta charset="utf-8">@include('reports._style')</head><body>
@php
$paymentLabels=['contado'=>'Pago completo','50_50'=>'50% al iniciar / 50% al entregar','tres_partes'=>'Tres pagos durante el proyecto','por_definir'=>'A definir con AGR Studio'];
$moduleLabels=['inicio'=>'Inicio','agenda'=>'Agenda','ordenes'=>'Órdenes','clientes'=>'Clientes','equipos'=>'Equipos','tecnicos'=>'Técnicos','inventario'=>'Inventario','pagos'=>'Pagos','garantias'=>'Garantías','historial'=>'Historial y reportes','buzon'=>'Mensajes'];
@endphp
<div class="brand"><h1>{{ $solicitud->codigo }} · {{ $solicitud->titulo }}</h1><div class="muted">Empresa: {{ $solicitud->empresa?->nombre_comercial ?: 'Sin empresa' }} · Responsable: {{ $solicitud->cliente?->nombre ?: 'Sin responsable' }} · Teléfono: {{ $solicitud->cliente?->telefono ?: 'Sin teléfono' }}</div><div class="muted">Generado: {{ now()->format('d/m/Y H:i') }}</div></div>
<p><span class="badge">{{ str_replace('_',' ',$solicitud->estado) }}</span></p>
@foreach($solicitud->cuestionario->secciones as $seccion)
@php
$answeredQuestions=$seccion->preguntas->filter(function($pregunta) use ($answers){$answer=$answers->get($pregunta->id);return $answer && (filled($answer->respuesta_texto) || !empty($answer->respuesta_json));});
@endphp
@if($answeredQuestions->isNotEmpty())
<div class="section"><h2>{{ $seccion->numero }}. {{ $seccion->titulo }}</h2>
@foreach($answeredQuestions as $pregunta)
@php($answer=$answers->get($pregunta->id))
<div style="margin-bottom:9px"><div class="question">{{ $pregunta->numero }}. {{ $pregunta->pregunta }}</div>@if(($answer?->origen ?? 'cliente')==='tecnico')<div class="muted" style="font-size:10px;margin-bottom:2px">Definición técnica AGR Studio · no reemplaza la respuesta original de la cliente</div>@endif<div class="answer">@if($answer?->respuesta_json){{ implode(', ', $answer->respuesta_json) }}@else{{ $answer?->respuesta_texto }}@endif</div></div>
@endforeach</div>
@endif
@endforeach
@if($solicitud->acuerdo_comercial_requerido || $solicitud->planViti || $solicitud->forma_pago_preferida)
<div class="section"><h2>Acuerdo comercial inicial</h2>
<p>Esta selección expresa la preferencia inicial del solicitante. El alcance, precio final y fechas quedan sujetos a revisión y aprobación del proyecto por AGR Studio.</p>
<p><strong>Plan preferido:</strong> {{ $solicitud->planViti?->nombre ?: 'Sin seleccionar' }}</p>
@if($solicitud->planViti?->descripcion)<p>{{ $solicitud->planViti->descripcion }}</p>@endif
<p><strong>Precio referencial del proyecto:</strong> {{ $solicitud->planViti?->precio_proyecto !== null ? number_format((float)$solicitud->planViti->precio_proyecto,2).' Bs' : 'Cotización personalizada' }}</p>
@if($solicitud->planViti?->precio_mensual !== null)<p><strong>Suscripción de plataforma:</strong> {{ number_format((float)$solicitud->planViti->precio_mensual,2) }} Bs / mes · {{ (int)($solicitud->planViti->dias_prueba ?? 0) }} días de prueba gratuita desde la habilitación de la beta. El primer cobro se prorratea por los días restantes del mes; desde el mes siguiente se cobra la mensualidad completa.</p>@endif
@if(is_array($solicitud->planViti?->modulos) && count($solicitud->planViti->modulos))<p><strong>Incluye:</strong> {{ collect($solicitud->planViti->modulos)->map(fn($m)=>$moduleLabels[$m]??ucfirst($m))->implode(', ') }}</p>@endif
<p><strong>Forma de pago preferida:</strong> {{ $paymentLabels[$solicitud->forma_pago_preferida] ?? 'Sin definir' }}</p>
<p><strong>Aceptación:</strong> {{ $solicitud->acuerdo_comercial_aceptado ? 'Aceptada' : 'Pendiente' }}@if($solicitud->acuerdo_comercial_nombre) · {{ $solicitud->acuerdo_comercial_nombre }}@endif @if($solicitud->acuerdo_comercial_fecha) · {{ $solicitud->acuerdo_comercial_fecha->format('d/m/Y') }}@endif</p>
</div>
@endif
<div class="section"><h2>Declaración final del solicitante</h2><p>Confirmo que las respuestas proporcionadas originalmente por la cliente representan de manera general la idea y las necesidades iniciales del sistema solicitado. Las definiciones técnicas agregadas posteriormente por AGR Studio se identifican expresamente como tales.</p><p><strong>Nombre:</strong> {{ $solicitud->declaracion_nombre ?: $solicitud->cliente?->nombre }}</p><p><strong>Fecha:</strong> {{ $solicitud->declaracion_fecha?->format('d/m/Y') ?: 'Sin confirmar' }} &nbsp;&nbsp; <strong>Declaración:</strong> {{ $solicitud->declaracion_aceptada ? 'Aceptada' : 'Pendiente' }}</p></div>
<div class="footer">VITI · Solicitud y acuerdo inicial de proyecto</div></body></html>