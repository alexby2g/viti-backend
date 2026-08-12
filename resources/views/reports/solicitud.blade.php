<!doctype html><html><head><meta charset="utf-8">@include('reports._style')</head><body>
@php
$paymentLabels=['contado'=>'Pago completo','50_50'=>'50% al iniciar / 50% al entregar','tres_partes'=>'40% al iniciar / 30% en avance / 30% al entregar','por_definir'=>'A definir con AGR Studio'];
$frequencyLabels=['mensual'=>'Mensual','anual'=>'Anual'];
$moduleLabels=['inicio'=>'Inicio','agenda'=>'Agenda','ordenes'=>'Órdenes','clientes'=>'Clientes','equipos'=>'Equipos','tecnicos'=>'Técnicos','inventario'=>'Inventario técnico','pagos'=>'Pagos y saldos','garantias'=>'Garantías','historial'=>'Historial y reportes','buzon'=>'Mensajes'];
$annualSaving=$solicitud->planViti?->precio_mensual !== null && $solicitud->planViti?->precio_anual !== null ? max(0,((float)$solicitud->planViti->precio_mensual*12)-(float)$solicitud->planViti->precio_anual) : null;
$sections=$solicitud->cuestionario?->secciones ?? collect();
$formatAnswer=function($answer){
    if(!$answer)return '';
    $json=$answer->respuesta_json;
    if(is_array($json)){
        $parts=[];
        array_walk_recursive($json,function($value)use(&$parts){if(is_scalar($value)&&$value!=='')$parts[]=(string)$value;});
        if(count($parts))return implode(', ',$parts);
    }elseif(is_scalar($json)&&$json!==''){
        return (string)$json;
    }
    return trim((string)($answer->respuesta_texto ?? ''));
};
@endphp
<div class="brand"><h1>{{ $solicitud->codigo }} · {{ $solicitud->titulo }}</h1><div class="muted">Empresa: {{ $solicitud->empresa?->nombre_comercial ?: 'Sin empresa' }} · Responsable: {{ $solicitud->cliente?->nombre ?: 'Sin responsable' }} · Teléfono: {{ $solicitud->cliente?->telefono ?: 'Sin teléfono' }}</div><div class="muted">Generado: {{ now()->format('d/m/Y H:i') }}</div></div>
<p><span class="badge">{{ str_replace('_',' ',$solicitud->estado ?: 'sin estado') }}</span></p>

@if($sections->isNotEmpty())
@foreach($sections as $seccion)
@php
$answeredQuestions=$seccion->preguntas->filter(function($pregunta) use ($answers,$formatAnswer){return $formatAnswer($answers->get($pregunta->id))!=='';});
@endphp
@if($answeredQuestions->isNotEmpty())
<div class="section"><h2>{{ $seccion->numero }}. {{ $seccion->titulo }}</h2>
@foreach($answeredQuestions as $pregunta)
@php($answer=$answers->get($pregunta->id))
<div style="margin-bottom:9px"><div class="question">{{ $pregunta->numero }}. {{ $pregunta->pregunta }}</div>@if(($answer?->origen ?? 'cliente')==='tecnico')<div class="muted" style="font-size:10px;margin-bottom:2px">Definición técnica AGR Studio · no reemplaza la respuesta original de la empresa</div>@endif<div class="answer">{{ $formatAnswer($answer) }}</div></div>
@endforeach</div>
@endif
@endforeach
@else
<div class="section"><h2>Información registrada</h2><p class="muted">Esta solicitud utiliza el flujo corto de VITI y no requiere el cuestionario institucional completo.</p></div>
@endif

@if($solicitud->acuerdo_comercial_requerido || $solicitud->planViti || $solicitud->forma_pago_preferida)
<div class="section"><h2>Acuerdo comercial inicial</h2>
<p>Esta selección expresa la preferencia inicial de la empresa. AGR Studio revisa que el plan cubra el alcance antes de aprobar el proyecto. Los desarrollos fuera del plan se cotizan por separado.</p>
<p><strong>Plan preferido:</strong> {{ $solicitud->planViti?->nombre ?: 'Sin seleccionar' }}</p>
@if($solicitud->planViti?->descripcion)<p>{{ $solicitud->planViti->descripcion }}</p>@endif
<p><strong>Implementación y configuración inicial:</strong> {{ $solicitud->planViti?->precio_proyecto !== null ? number_format((float)$solicitud->planViti->precio_proyecto,2).' Bs' : 'Cotización personalizada' }}</p>
@if($solicitud->planViti && ($solicitud->planViti->precio_mensual !== null || $solicitud->planViti->precio_anual !== null))
<p><strong>Suscripción:</strong>
@if($solicitud->planViti->precio_mensual !== null){{ number_format((float)$solicitud->planViti->precio_mensual,2) }} Bs / mes@endif
@if($solicitud->planViti->precio_mensual !== null && $solicitud->planViti->precio_anual !== null) · @endif
@if($solicitud->planViti->precio_anual !== null){{ number_format((float)$solicitud->planViti->precio_anual,2) }} Bs / año@endif
· {{ (int)($solicitud->planViti->dias_prueba ?? 0) }} días de prueba.</p>
@if($annualSaving !== null && $annualSaving > 0)<p><strong>Ahorro con anualidad:</strong> {{ number_format($annualSaving,2) }} Bs frente a 12 mensualidades.</p>@endif
<p><strong>Modalidad preferida:</strong> {{ $frequencyLabels[$solicitud->frecuencia_suscripcion_preferida] ?? 'Sin definir' }}.</p>
@if($solicitud->frecuencia_suscripcion_preferida==='mensual')<p class="muted">Después de la prueba, el primer cobro mensual se calcula proporcionalmente por los días restantes del mes.</p>@elseif($solicitud->frecuencia_suscripcion_preferida==='anual')<p class="muted">Después de la prueba se aplica la anualidad completa.</p>@endif
@endif
@if(is_array($solicitud->planViti?->modulos) && count($solicitud->planViti->modulos))
@php
$included=[];
foreach($solicitud->planViti->modulos as $module){$included[]=$moduleLabels[$module]??ucfirst((string)$module);}
@endphp
<p><strong>Incluye:</strong> {{ implode(', ',$included) }}</p>
@endif
<p><strong>Forma de pago de la implementación:</strong> {{ $paymentLabels[$solicitud->forma_pago_preferida] ?? 'Sin definir' }}</p>
<p><strong>Aceptación:</strong> {{ $solicitud->acuerdo_comercial_aceptado ? 'Aceptada' : 'Pendiente' }}@if($solicitud->acuerdo_comercial_nombre) · {{ $solicitud->acuerdo_comercial_nombre }}@endif @if($solicitud->acuerdo_comercial_fecha) · {{ $solicitud->acuerdo_comercial_fecha->format('d/m/Y') }}@endif</p>
</div>
@endif

<div class="section"><h2>Declaración final</h2><p>Confirmo que la información proporcionada por la empresa representa de manera general la necesidad inicial del sistema solicitado. Las definiciones técnicas agregadas posteriormente por AGR Studio se identifican expresamente como tales.</p><p><strong>Nombre:</strong> {{ $solicitud->declaracion_nombre ?: $solicitud->cliente?->nombre }}</p><p><strong>Fecha:</strong> {{ $solicitud->declaracion_fecha?->format('d/m/Y') ?: 'Sin confirmar' }} &nbsp;&nbsp; <strong>Declaración:</strong> {{ $solicitud->declaracion_aceptada ? 'Aceptada' : 'Pendiente' }}</p></div>
<div class="footer">VITI · Solicitud y acuerdo comercial inicial</div></body></html>