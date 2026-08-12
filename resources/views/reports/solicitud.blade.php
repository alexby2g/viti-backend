<!doctype html><html><head><meta charset="utf-8">@include('reports._style')</head><body>
<?php
$plan=$solicitud->planViti;
$paymentLabels=['contado'=>'Pago completo','50_50'=>'50% al iniciar / 50% al entregar','tres_partes'=>'40% al iniciar / 30% en avance / 30% al entregar','por_definir'=>'A definir con AGR Studio'];
$frequencyLabels=['mensual'=>'Mensual','anual'=>'Anual'];
$moduleLabels=['inicio'=>'Inicio','agenda'=>'Agenda','ordenes'=>'Órdenes','clientes'=>'Clientes','equipos'=>'Equipos','tecnicos'=>'Técnicos','inventario'=>'Inventario técnico','pagos'=>'Pagos y saldos','garantias'=>'Garantías','historial'=>'Historial y reportes','buzon'=>'Mensajes'];
$annualSaving=$plan && $plan->precio_mensual!==null && $plan->precio_anual!==null ? max(0,((float)$plan->precio_mensual*12)-(float)$plan->precio_anual) : null;
$included=[];
if($plan && is_array($plan->modulos)) foreach($plan->modulos as $module) $included[]=$moduleLabels[$module]??ucfirst((string)$module);
$formatAnswer=function($answer){
    if(!$answer)return '';
    $json=$answer->respuesta_json;
    if(is_array($json)){
        $parts=[];
        array_walk_recursive($json,function($value)use(&$parts){if(is_scalar($value)&&$value!=='')$parts[]=(string)$value;});
        if($parts)return implode(', ',$parts);
    }elseif(is_scalar($json)&&$json!=='') return (string)$json;
    return trim((string)($answer->respuesta_texto??''));
};
$answeredSections=[];
foreach(($solicitud->cuestionario?->secciones??collect()) as $section){
    $questions=[];
    foreach(($section->preguntas??collect()) as $question){
        $answer=$answers->get($question->id);
        $formatted=$formatAnswer($answer);
        if($formatted!=='')$questions[]=['question'=>$question,'answer'=>$answer,'formatted'=>$formatted];
    }
    if($questions)$answeredSections[]=['section'=>$section,'questions'=>$questions];
}
$agreementVisible=(bool)($solicitud->acuerdo_comercial_requerido||$plan||$solicitud->forma_pago_preferida);
$subscriptionParts=[];
if($plan?->precio_mensual!==null)$subscriptionParts[]=number_format((float)$plan->precio_mensual,2).' Bs / mes';
if($plan?->precio_anual!==null)$subscriptionParts[]=number_format((float)$plan->precio_anual,2).' Bs / año';
?>
<div class="brand">
  <h1><?= e($solicitud->codigo) ?> · <?= e($solicitud->titulo) ?></h1>
  <div class="muted">Empresa: <?= e($solicitud->empresa?->nombre_comercial ?: 'Sin empresa') ?> · Responsable: <?= e($solicitud->cliente?->nombre ?: 'Sin responsable') ?> · Teléfono: <?= e($solicitud->cliente?->telefono ?: 'Sin teléfono') ?></div>
  <div class="muted">Generado: <?= e(now()->format('d/m/Y H:i')) ?></div>
</div>
<p><span class="badge"><?= e(str_replace('_',' ',$solicitud->estado ?: 'sin estado')) ?></span></p>

<?php if($answeredSections): ?>
  <?php foreach($answeredSections as $bundle): $section=$bundle['section']; ?>
    <div class="section">
      <h2><?= e($section->numero) ?>. <?= e($section->titulo) ?></h2>
      <?php foreach($bundle['questions'] as $entry): $question=$entry['question']; $answer=$entry['answer']; ?>
        <div style="margin-bottom:9px">
          <div class="question"><?= e($question->numero) ?>. <?= e($question->pregunta) ?></div>
          <?php if(($answer?->origen??'cliente')==='tecnico'): ?><div class="muted" style="font-size:10px;margin-bottom:2px">Definición técnica AGR Studio · no reemplaza la respuesta original de la empresa</div><?php endif; ?>
          <div class="answer"><?= e($entry['formatted']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php else: ?>
  <div class="section"><h2>Información registrada</h2><p class="muted">Esta solicitud utiliza el flujo corto de VITI. La configuración adicional es opcional y puede completarse durante la revisión.</p></div>
<?php endif; ?>

<?php if($agreementVisible): ?>
<div class="section">
  <h2>Acuerdo comercial inicial</h2>
  <p>Esta selección expresa la preferencia inicial de la empresa. AGR Studio revisa que el plan cubra el alcance antes de aprobar el proyecto. Los desarrollos fuera del plan se cotizan por separado.</p>
  <p><strong>Plan preferido:</strong> <?= e($plan?->nombre ?: 'Sin seleccionar') ?></p>
  <?php if($plan?->descripcion): ?><p><?= e($plan->descripcion) ?></p><?php endif; ?>
  <p><strong>Implementación y configuración inicial:</strong> <?= e($plan?->precio_proyecto!==null ? number_format((float)$plan->precio_proyecto,2).' Bs' : 'Cotización personalizada') ?></p>
  <?php if($subscriptionParts): ?>
    <p><strong>Suscripción:</strong> <?= e(implode(' · ',$subscriptionParts)) ?> · <?= e((int)($plan->dias_prueba??0)) ?> días de prueba.</p>
    <?php if($annualSaving!==null && $annualSaving>0): ?><p><strong>Ahorro con anualidad:</strong> <?= e(number_format($annualSaving,2)) ?> Bs frente a 12 mensualidades.</p><?php endif; ?>
    <p><strong>Modalidad preferida:</strong> <?= e($frequencyLabels[$solicitud->frecuencia_suscripcion_preferida]??'Sin definir') ?>.</p>
    <?php if($solicitud->frecuencia_suscripcion_preferida==='mensual'): ?><p class="muted">Después de la prueba, el primer cobro mensual se calcula proporcionalmente por los días restantes del mes.</p><?php endif; ?>
    <?php if($solicitud->frecuencia_suscripcion_preferida==='anual'): ?><p class="muted">Después de la prueba se aplica la anualidad completa.</p><?php endif; ?>
  <?php endif; ?>
  <?php if($included): ?><p><strong>Incluye:</strong> <?= e(implode(', ',$included)) ?></p><?php endif; ?>
  <p><strong>Forma de pago de la implementación:</strong> <?= e($paymentLabels[$solicitud->forma_pago_preferida]??'Sin definir') ?></p>
  <p><strong>Aceptación:</strong> <?= e($solicitud->acuerdo_comercial_aceptado?'Aceptada':'Pendiente') ?><?php if($solicitud->acuerdo_comercial_nombre): ?> · <?= e($solicitud->acuerdo_comercial_nombre) ?><?php endif; ?><?php if($solicitud->acuerdo_comercial_fecha): ?> · <?= e($solicitud->acuerdo_comercial_fecha->format('d/m/Y')) ?><?php endif; ?></p>
</div>
<?php endif; ?>

<div class="section">
  <h2>Declaración final</h2>
  <p>Confirmo que la información proporcionada por la empresa representa de manera general la necesidad inicial del sistema solicitado. Las definiciones técnicas agregadas posteriormente por AGR Studio se identifican expresamente como tales.</p>
  <p><strong>Nombre:</strong> <?= e($solicitud->declaracion_nombre ?: $solicitud->cliente?->nombre) ?></p>
  <p><strong>Fecha:</strong> <?= e($solicitud->declaracion_fecha?->format('d/m/Y') ?: 'Sin confirmar') ?> &nbsp;&nbsp; <strong>Declaración:</strong> <?= e($solicitud->declaracion_aceptada?'Aceptada':'Pendiente') ?></p>
</div>
<div class="footer">VITI · Solicitud y acuerdo comercial inicial</div></body></html>