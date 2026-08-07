<!doctype html><html><head><meta charset="utf-8">@include('reports._style')</head><body>
<div class="brand"><h1>{{ $solicitud->codigo }} · {{ $solicitud->titulo }}</h1><div class="muted">Empresa: {{ $solicitud->empresa?->nombre_comercial ?: 'Sin empresa' }} · Responsable: {{ $solicitud->cliente?->nombre ?: 'Sin cliente' }} · Teléfono: {{ $solicitud->cliente?->telefono ?: 'Sin teléfono' }}</div><div class="muted">Generado: {{ now()->format('d/m/Y H:i') }}</div></div>
<p><span class="badge">{{ str_replace('_',' ',$solicitud->estado) }}</span></p>
@foreach($solicitud->cuestionario->secciones as $seccion)
<div class="section"><h2>{{ $seccion->numero }}. {{ $seccion->titulo }}</h2>
@foreach($seccion->preguntas as $pregunta)
@php($answer=$answers->get($pregunta->id))
<div style="margin-bottom:9px"><div class="question">{{ $pregunta->numero }}. {{ $pregunta->pregunta }}</div><div class="answer">@if($answer?->respuesta_json){{ implode(', ', $answer->respuesta_json) }}@else{{ $answer?->respuesta_texto ?: 'Sin respuesta' }}@endif</div></div>
@endforeach</div>@endforeach
<div class="section"><h2>Declaración final del cliente</h2><p>Confirmo que las respuestas proporcionadas representan de manera general la idea y las necesidades iniciales del sistema solicitado.</p><p><strong>Nombre del cliente:</strong> {{ $solicitud->declaracion_nombre ?: $solicitud->cliente?->nombre }}</p><p><strong>Fecha:</strong> {{ $solicitud->declaracion_fecha?->format('d/m/Y') ?: 'Sin confirmar' }} &nbsp;&nbsp; <strong>Declaración:</strong> {{ $solicitud->declaracion_aceptada ? 'Aceptada' : 'Pendiente' }}</p></div>
<div class="footer">VITI · Cuestionario para definir un sistema</div></body></html>
