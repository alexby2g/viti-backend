<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('solicitudes_sistema') || !Schema::hasTable('proyectos')) return;

        $solicitud = DB::table('solicitudes_sistema')->where('codigo', 'SOL-00007')->first();
        if (!$solicitud || !$solicitud->empresa_id || !$solicitud->cliente_id) return;

        $project = DB::table('proyectos')->where('solicitud_id', $solicitud->id)->first();
        if (!$project) return;

        $now = now();
        $planId = Schema::hasTable('planes_viti')
            ? DB::table('planes_viti')->where('codigo', 'profesional-1950')->value('id')
            : null;

        if ($planId) {
            DB::table('solicitudes_sistema')->where('id', $solicitud->id)->update([
                'plan_viti_id' => $planId,
                'presupuesto_estimado' => 1950,
                'forma_pago_preferida' => $solicitud->forma_pago_preferida ?: '50_50',
                'updated_at' => $now,
            ]);
            DB::table('empresas')->where('id', $solicitud->empresa_id)->update([
                'plan_viti_id' => $planId,
                'updated_at' => $now,
            ]);
        }

        DB::table('proyectos')->where('id', $project->id)->update([
            'fase' => 'desarrollo',
            'estado' => 'activo',
            'progreso' => max(50, (int) ($project->progreso ?? 0)),
            'fecha_beta' => $project->fecha_beta ?: '2026-08-24',
            'fecha_entrega' => $project->fecha_entrega ?: '2026-09-04',
            'precio_estimado' => 1950,
            'complejidad' => 'media-alta',
            'dias_estimados' => 19,
            'observaciones' => trim(($project->observaciones ? $project->observaciones."\n\n" : '').'Estimación técnica VITI: complejidad media-alta. Beta referencial 24/08/2026 y entrega estimada 04/09/2026, sujetas a pruebas y validación de la empresa. Precio de proyecto propuesto: Bs 1.950 bajo Plan Profesional. La suscripción mensual de Bs 50 comienza únicamente después de habilitar la beta y completar 14 días de prueba gratuita; el primer cobro se prorratea por los días restantes del mes.'),
            'updated_at' => $now,
        ]);

        $this->completeTechnicalAnswers((int) $solicitud->id, $now);
        $this->ensureTechnicalApp((int) $solicitud->empresa_id, (int) $project->id, $now);
        $this->publishProgress((int) $project->id, $now);
    }

    private function completeTechnicalAnswers(int $solicitudId, $now): void
    {
        if (!Schema::hasTable('cuestionario_preguntas') || !Schema::hasTable('solicitud_respuestas')) return;

        $answers = [
            10 => 'Propuesta técnica AGR Studio: digitalizar el registro de clientes y computadoras, recepción del equipo, diagnóstico, seguimiento de la reparación, asignación de técnico, cobros e historial del servicio.',
            11 => 'Propuesta técnica AGR Studio: reducir pérdida de información, consultar rápidamente el historial de cada computadora y dar al cliente una atención más ordenada y trazable.',
            14 => 'Propuesta técnica AGR Studio: no todos verán ni modificarán lo mismo. Los permisos se separarán según el rol para proteger información técnica y financiera.',
            15 => 'Propuesta técnica AGR Studio: sí. Se manejarán roles de Propietario/Administrador, Secretaría y Técnico.',
            16 => 'Propuesta técnica AGR Studio: el Propietario/Administrador tendrá control general; Secretaría podrá registrar clientes, computadoras, citas y pagos; el Técnico trabajará con órdenes asignadas, diagnóstico, fotografías, solución y observaciones, sin acceso a configuraciones administrativas sensibles.',
            21 => 'Propuesta técnica AGR Studio: se podrán corregir datos de contacto, datos del equipo, citas y una orden mientras esté abierta. Los servicios cerrados conservarán historial y no se eliminarán sin dejar trazabilidad.',
            23 => 'Propuesta técnica AGR Studio: sí. Cada reparación pasará por estados definidos para conocer en qué punto se encuentra.',
            24 => 'Propuesta técnica AGR Studio: Recibido → En diagnóstico → Esperando aprobación → En reparación → En pruebas → Listo para entregar → Entregado. Si el cliente no autoriza, se cerrará como Sin reparación.',
            25 => 'Propuesta técnica AGR Studio: Secretaría o Administración registran recepción y cita; el Técnico actualiza diagnóstico, reparación y pruebas; Administración confirma entrega, cierre y situaciones excepcionales.',
            26 => 'Propuesta técnica AGR Studio: sí. VITI mostrará avisos internos cuando una orden requiera aprobación, cambie a lista para entrega o tenga pago pendiente.',
            27 => 'Propuesta técnica AGR Studio: búsqueda rápida por cliente, teléfono, código de orden, marca/modelo/serie de la computadora, técnico, estado y fecha.',
            28 => 'Propuesta técnica AGR Studio: servicios realizados por periodo y técnico, órdenes por estado, pagos y saldos pendientes, e historial por cliente y computadora.',
            29 => 'Propuesta técnica AGR Studio: sí. Los reportes principales deberán poder visualizarse y descargarse en PDF para impresión cuando sea necesario.',
            30 => 'Propuesta técnica AGR Studio: código y fecha de la orden, cliente, computadora, problema informado, diagnóstico, trabajo realizado, técnico, estado, monto, pagos y observaciones.',
            33 => 'Propuesta técnica AGR Studio: sí. Se permitirán anticipos y pagos parciales, dejando visible el saldo pendiente.',
            34 => 'Propuesta técnica AGR Studio: se generará un comprobante interno del servicio y pago. La facturación fiscal no forma parte de esta primera versión salvo contratación posterior.',
            35 => 'Propuesta técnica AGR Studio: sí. Cada orden mostrará total, pagado y saldo pendiente.',
            36 => 'Propuesta técnica AGR Studio: sí, mediante notificaciones internas de VITI; las notificaciones push podrán incorporarse en la etapa móvil.',
            37 => 'Propuesta técnica AGR Studio: inicialmente dentro de VITI y, en la versión móvil, notificaciones del dispositivo cuando correspondan.',
            38 => 'Propuesta técnica AGR Studio: nueva cita, orden esperando aprobación, equipo listo para entregar, pago pendiente y cambios relevantes del servicio.',
            39 => 'Propuesta técnica AGR Studio: no se requiere integración externa obligatoria en la primera versión. Se priorizará que el sistema base sea estable.',
            40 => 'Propuesta técnica AGR Studio: computadora y teléfono celular, con diseño responsivo.',
            42 => 'Propuesta técnica AGR Studio: la primera versión trabajará con conexión a internet. Un modo offline se evaluará posteriormente para no comprometer la consistencia de la base de datos.',
            45 => 'Propuesta técnica AGR Studio: mientras no se entregue una identidad visual propia, se utilizará el nombre Soporte Vital PC con una interfaz tecnológica limpia y profesional.',
            46 => 'Propuesta técnica AGR Studio: azul oscuro, azul tecnológico y acentos verdes, manteniendo buen contraste y lectura.',
            47 => 'Propuesta técnica AGR Studio: referencia de diseño tipo panel administrativo simple, con navegación corta y acciones visibles sin saturar la pantalla.',
            49 => 'Propuesta técnica AGR Studio: se priorizarán tamaño de texto legible, contraste y funcionamiento responsivo. No se requieren funciones especiales de accesibilidad en esta primera versión.',
            53 => 'Propuesta técnica AGR Studio: sí. Se registrarán acciones importantes para saber quién creó o modificó información sensible.',
            54 => 'Propuesta técnica AGR Studio: sí. Técnicos y Secretaría tendrán información limitada según su función; Administración tendrá la vista completa.',
            55 => 'Propuesta técnica AGR Studio: sí. El sistema se diseñará para agregar inventario avanzado, notificaciones móviles, nuevas estadísticas o integraciones sin rehacer la base.',
            56 => 'Propuesta técnica AGR Studio: inicialmente se considera un volumen bajo a medio. La estructura se dejará preparada para crecer sin depender de un número fijo de registros por mes.',
            57 => 'Propuesta técnica AGR Studio: sí. Se recomienda iniciar con el núcleo operativo y agregar funciones avanzadas una vez que el flujo real haya sido probado.',
            58 => 'Propuesta técnica AGR Studio: clientes, computadoras/equipos, técnicos, órdenes de servicio, agenda, fotografías de recepción, historial, pagos y reportes básicos.',
            59 => 'Propuesta técnica AGR Studio: inventario avanzado, integraciones externas, funcionamiento offline y automatizaciones móviles avanzadas pueden quedar para una segunda versión.',
            62 => 'Definición comercial AGR Studio: el desarrollo y la suscripción se manejan por separado. El Plan Profesional propuesto tiene un desarrollo estimado de Bs 1.950 y mantenimiento/plataforma de Bs 50 mensuales. Incluye 14 días de prueba gratuita desde la habilitación de la beta; el primer mes se cobra solo por los días posteriores a la prueba y desde el mes siguiente se cobra la mensualidad completa.',
            64 => 'Propuesta técnica AGR Studio: la carga inicial será realizada por el Administrador o Secretaría del negocio con acompañamiento de VITI durante la puesta en marcha.',
            66 => 'Propuesta técnica AGR Studio: revisión por hitos y, durante desarrollo activo, al menos una actualización semanal visible en VITI.',
            69 => 'Propuesta técnica AGR Studio: evitar pantallas complicadas, pasos innecesarios y funciones que hagan lento el registro de un servicio.',
            70 => 'Definición técnica AGR Studio: priorizar una primera versión estable, simple y trazable. La aplicación móvil y funciones adicionales se liberarán únicamente después de validar el flujo web y la seguridad de los datos.',
        ];

        $questions = DB::table('cuestionario_preguntas')->whereIn('numero', array_keys($answers))->get()->keyBy('numero');
        foreach ($answers as $number => $text) {
            $question = $questions->get($number);
            if (!$question) continue;
            $existing = DB::table('solicitud_respuestas')
                ->where('solicitud_id', $solicitudId)
                ->where('pregunta_id', $question->id)
                ->first();
            $hasAnswer = $existing && (filled($existing->respuesta_texto) || filled($existing->respuesta_json));
            if ($hasAnswer) continue;

            DB::table('solicitud_respuestas')->updateOrInsert(
                ['solicitud_id' => $solicitudId, 'pregunta_id' => $question->id],
                [
                    'respuesta_texto' => $text,
                    'respuesta_json' => null,
                    'origen' => 'tecnico',
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function ensureTechnicalApp(int $empresaId, int $projectId, $now): void
    {
        if (!Schema::hasTable('catalogo_aplicaciones') || !Schema::hasTable('aplicaciones')) return;

        DB::table('catalogo_aplicaciones')->updateOrInsert(
            ['clave' => 'servicio-tecnico'],
            [
                'nombre' => 'Servicio Técnico VITI',
                'descripcion' => 'Gestión de clientes, equipos, técnicos, órdenes, agenda, pagos e historial para talleres y servicios técnicos.',
                'icono' => 'computer',
                'tipo' => 'web',
                'ruta_base' => '/apps/servicio-tecnico',
                'activo' => true,
                'solicitable' => false,
                'orden' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $catalogId = DB::table('catalogo_aplicaciones')->where('clave', 'servicio-tecnico')->value('id');
        $existing = DB::table('aplicaciones')->where('proyecto_id', $projectId)->first();
        $payload = [
            'empresa_id' => $empresaId,
            'proyecto_id' => $projectId,
            'catalogo_aplicacion_id' => $catalogId,
            'nombre' => 'Soporte Vital PC',
            'slug' => $existing?->slug ?: 'soporte-vital-pc-'.Str::lower(Str::random(4)),
            'version' => '0.5.0',
            'tipo' => 'web',
            'tecnologias' => 'Laravel, Quasar, PostgreSQL',
            'entorno' => 'beta',
            'estado' => 'en_pruebas',
            'acceso_cliente' => false,
            'url' => null,
            'url_administracion' => null,
            'proveedor_hosting' => 'Vercel + Render + Neon',
            'notas' => 'Avance funcional 50%. Núcleo operativo disponible para revisión interna: clientes, computadoras/equipos, técnicos, órdenes y agenda base. Pendiente: fotografías completas, pagos finales, reportes, pruebas de aceptación y versión móvil.',
            'updated_at' => $now,
        ];
        if (!$existing) {
            $payload['provisionado_at'] = $now;
            $payload['created_at'] = $now;
            DB::table('aplicaciones')->insert($payload);
        } else {
            DB::table('aplicaciones')->where('id', $existing->id)->update($payload);
        }
    }

    private function publishProgress(int $projectId, $now): void
    {
        if (!Schema::hasTable('proyecto_avances') || !Schema::hasTable('usuarios')) return;
        $creator = DB::table('usuarios')->where('estado', 'activo')->whereIn('rol', ['superadmin','administrador'])
            ->orderByRaw("CASE WHEN rol = 'superadmin' THEN 0 ELSE 1 END")->value('id');
        if (!$creator) return;

        $items = [
            [30, 'Definición técnica y permisos', 'Se completó la definición técnica de las respuestas que estaban pendientes, diferenciándolas de las respuestas originales de la cliente. Se definieron roles de Administración, Secretaría y Técnico, además de reglas para proteger información privada.'],
            [40, 'Flujo de reparación y estructura de datos', 'Quedó estructurado el flujo de trabajo: recepción, diagnóstico, aprobación, reparación, pruebas y entrega. Se definieron búsquedas, historial, saldos, reportes y datos obligatorios de cliente y computadora.'],
            [50, 'Núcleo funcional listo para revisión interna', 'La versión 0.5 ya cuenta con base funcional para clientes, computadoras/equipos, técnicos, órdenes de servicio y agenda. La aplicación se mantiene en pruebas internas; fotografías completas, pagos finales, reportes, validación y versión móvil continúan en desarrollo.'],
        ];

        foreach ($items as [$progress, $title, $description]) {
            $exists = DB::table('proyecto_avances')->where('proyecto_id', $projectId)->where('titulo', $title)->exists();
            if ($exists) continue;
            DB::table('proyecto_avances')->insert([
                'proyecto_id' => $projectId,
                'creado_por' => $creator,
                'fase' => 'desarrollo',
                'area' => $progress === 30 ? 'analisis' : 'desarrollo',
                'titulo' => $title,
                'descripcion' => $description,
                'progreso' => $progress,
                'visible_cliente' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Se preserva el historial real del proyecto y de la ficha técnica.
    }
};
