<?php

namespace Database\Seeders;

use App\Models\{Aplicacion,Cliente,Cuestionario,Empresa,PeluqueriaAtencion,PeluqueriaCita,PeluqueriaCliente,PeluqueriaPago,PeluqueriaPersonal,PeluqueriaServicio,Proyecto,ProyectoAvance,SolicitudSistema,Usuario};
use App\Support\Code;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoPeluqueriaSeeder extends Seeder
{
    public const USERNAME = 'peluqueria.demo';
    public const PASSWORD = 'VitiDemo2026!';

    public function run(): void
    {
        DB::transaction(function (): void {
            $cliente = Cliente::query()->updateOrCreate(
                ['telefono' => '70000123'],
                [
                    'nombre' => 'María Fernanda López Demo',
                    'whatsapp' => '70000123',
                    'documento' => '12345678',
                    'ci_expedido' => 'SC',
                    'ciudad' => 'Santa Cruz de la Sierra',
                    'direccion' => 'Av. Demo 123, Equipetrol',
                    'foto_verificada' => false,
                    'perfil_completo_at' => now(),
                    'observaciones' => 'Cliente ficticio creado para probar la experiencia SaaS de VITI.',
                    'estado' => 'informacion_recibida',
                    'canal_origen' => 'demo_viti',
                ]
            );

            $usuario = Usuario::query()->updateOrCreate(
                ['usuario' => self::USERNAME],
                [
                    'cliente_id' => $cliente->id,
                    'nombre' => 'María Fernanda',
                    'apellido' => 'López Demo',
                    'telefono' => '70000123',
                    'password' => Hash::make(self::PASSWORD),
                    'rol' => 'cliente',
                    'estado' => 'activo',
                ]
            );

            $empresa = Empresa::query()
                ->where('cliente_id', $cliente->id)
                ->where('nombre_comercial', 'Salón Bella VITI Demo')
                ->first();

            if (!$empresa) {
                $empresa = Empresa::query()->create([
                    'cliente_id' => $cliente->id,
                    'codigo' => Code::next('empresas', 'EMP'),
                    'nombre_comercial' => 'Salón Bella VITI Demo',
                    'razon_social' => 'María Fernanda López Demo',
                    'actividad' => 'Peluquería, coloración, tratamientos capilares y barbería.',
                    'telefono' => '70000123',
                    'whatsapp' => '70000123',
                    'ciudad' => 'Santa Cruz de la Sierra',
                    'direccion' => 'Av. Demo 123, Equipetrol',
                    'observaciones' => 'Negocio ficticio para demostración de VITI Apps.',
                    'estado' => 'activo',
                ]);
            } else {
                $empresa->update([
                    'actividad' => 'Peluquería, coloración, tratamientos capilares y barbería.',
                    'telefono' => '70000123',
                    'whatsapp' => '70000123',
                    'ciudad' => 'Santa Cruz de la Sierra',
                    'direccion' => 'Av. Demo 123, Equipetrol',
                    'estado' => 'activo',
                ]);
            }

            $cuestionarioId = Cuestionario::query()->where('activo', true)->latest('id')->value('id');

            $solicitud = SolicitudSistema::query()
                ->where('cliente_id', $cliente->id)
                ->where('empresa_id', $empresa->id)
                ->where('titulo', 'Sistema de gestión para Salón Bella')
                ->first();

            if (!$solicitud) {
                $solicitud = SolicitudSistema::query()->create([
                    'empresa_id' => $empresa->id,
                    'cliente_id' => $cliente->id,
                    'cuestionario_id' => $cuestionarioId,
                    'codigo' => Code::next('solicitudes_sistema', 'SOL'),
                    'public_token' => Str::random(48),
                    'publico_habilitado' => false,
                    'titulo' => 'Sistema de gestión para Salón Bella',
                    'resumen' => 'La cliente solicitó una aplicación para administrar citas, clientes, servicios, personal, atenciones, pagos e historial de su peluquería desde VITI.',
                    'estado' => 'convertida',
                    'prioridad' => 'normal',
                    'enviado_at' => now()->subDays(18),
                    'aprobado_at' => now()->subDays(16),
                    'declaracion_aceptada' => true,
                    'declaracion_nombre' => 'María Fernanda López Demo',
                    'declaracion_fecha' => now()->subDays(18)->toDateString(),
                ]);
            }

            $proyecto = Proyecto::query()->where('solicitud_id', $solicitud->id)->first();
            if (!$proyecto) {
                $proyecto = Proyecto::query()->create([
                    'solicitud_id' => $solicitud->id,
                    'empresa_id' => $empresa->id,
                    'cliente_id' => $cliente->id,
                    'codigo' => Code::next('proyectos', 'PRO'),
                    'nombre' => 'Peluquería VITI · Salón Bella',
                    'descripcion' => 'Implementación de la aplicación Peluquería dentro de la plataforma SaaS VITI.',
                    'fase' => 'finalizado',
                    'estado' => 'activo',
                    'progreso' => 100,
                    'fecha_inicio' => now()->subDays(15)->toDateString(),
                    'fecha_beta' => now()->subDays(7)->toDateString(),
                    'fecha_entrega' => now()->subDay()->toDateString(),
                    'produccion_url' => rtrim((string) env('FRONTEND_APP_URL', 'https://viti-frontend.vercel.app'), '/').'/mi-aplicaciones/peluqueria',
                    'observaciones' => 'Aplicación demo activa dentro de VITI.',
                ]);
            } else {
                $proyecto->update([
                    'fase' => 'finalizado',
                    'estado' => 'activo',
                    'progreso' => 100,
                    'fecha_entrega' => now()->subDay()->toDateString(),
                    'produccion_url' => rtrim((string) env('FRONTEND_APP_URL', 'https://viti-frontend.vercel.app'), '/').'/mi-aplicaciones/peluqueria',
                ]);
            }

            Aplicacion::query()->updateOrCreate(
                ['proyecto_id' => $proyecto->id],
                [
                    'empresa_id' => $empresa->id,
                    'nombre' => 'Peluquería VITI',
                    'slug' => 'peluqueria-viti-demo',
                    'version' => '1.0',
                    'tipo' => 'web',
                    'tecnologias' => 'VITI Core · Laravel · Quasar',
                    'entorno' => 'produccion',
                    'estado' => 'activo',
                    'url' => rtrim((string) env('FRONTEND_APP_URL', 'https://viti-frontend.vercel.app'), '/').'/mi-aplicaciones/peluqueria',
                    'url_administracion' => rtrim((string) env('FRONTEND_APP_URL', 'https://viti-frontend.vercel.app'), '/').'/apps/peluqueria',
                    'proveedor_hosting' => 'VITI SaaS',
                    'notas' => 'Aplicación ficticia para validar la experiencia de un cliente VITI.',
                    'publicado_at' => now()->subDay(),
                ]
            );

            $creator = Usuario::query()->where('rol', 'superadmin')->first() ?: $usuario;
            ProyectoAvance::query()->updateOrCreate(
                ['proyecto_id' => $proyecto->id, 'titulo' => 'Aplicación publicada en VITI'],
                [
                    'creado_por' => $creator->id,
                    'fase' => 'finalizado',
                    'area' => 'general',
                    'descripcion' => 'La aplicación Peluquería está disponible en producción dentro del portal del cliente.',
                    'progreso' => 100,
                    'visible_cliente' => true,
                ]
            );

            $this->seedPeluqueria($empresa->id);
        });
    }

    private function seedPeluqueria(int $empresaId): void
    {
        PeluqueriaPago::query()->where('empresa_id', $empresaId)->delete();
        PeluqueriaAtencion::query()->where('empresa_id', $empresaId)->delete();
        PeluqueriaCita::query()->where('empresa_id', $empresaId)->delete();
        PeluqueriaPersonal::query()->where('empresa_id', $empresaId)->delete();
        PeluqueriaServicio::query()->where('empresa_id', $empresaId)->delete();
        PeluqueriaCliente::query()->where('empresa_id', $empresaId)->delete();

        $ana = PeluqueriaPersonal::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Ana Rojas',
            'telefono' => '71000001',
            'especialidad' => 'Coloración y tratamientos',
            'horario_inicio' => '09:00',
            'horario_fin' => '18:00',
            'porcentaje_comision' => 30,
            'activo' => true,
        ]);
        $carlos = PeluqueriaPersonal::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Carlos Méndez',
            'telefono' => '71000002',
            'especialidad' => 'Corte y barbería',
            'horario_inicio' => '10:00',
            'horario_fin' => '19:00',
            'porcentaje_comision' => 25,
            'activo' => true,
        ]);

        $corte = PeluqueriaServicio::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Corte femenino',
            'categoria' => 'Cabello',
            'duracion_minutos' => 45,
            'precio' => 60,
            'descripcion' => 'Lavado, corte y acabado.',
            'activo' => true,
        ]);
        $tinte = PeluqueriaServicio::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Tinte completo',
            'categoria' => 'Coloración',
            'duracion_minutos' => 120,
            'precio' => 220,
            'descripcion' => 'Coloración completa con lavado y secado.',
            'activo' => true,
        ]);
        $barba = PeluqueriaServicio::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Corte + barba',
            'categoria' => 'Barbería',
            'duracion_minutos' => 60,
            'precio' => 80,
            'activo' => true,
        ]);

        $laura = PeluqueriaCliente::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Laura Gutiérrez',
            'telefono' => '72000001',
            'whatsapp' => '72000001',
            'fecha_nacimiento' => '1996-04-15',
            'sexo' => 'Femenino',
            'observaciones' => 'Prefiere tonos cálidos y corte en capas.',
            'activo' => true,
        ]);
        $diego = PeluqueriaCliente::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Diego Vargas',
            'telefono' => '72000002',
            'whatsapp' => '72000002',
            'sexo' => 'Masculino',
            'observaciones' => 'Corte degradado bajo.',
            'activo' => true,
        ]);
        $sofia = PeluqueriaCliente::query()->create([
            'empresa_id' => $empresaId,
            'nombre' => 'Sofía Pereira',
            'telefono' => '72000003',
            'whatsapp' => '72000003',
            'sexo' => 'Femenino',
            'activo' => true,
        ]);

        $todayCita = PeluqueriaCita::query()->create([
            'empresa_id' => $empresaId,
            'cliente_id' => $laura->id,
            'servicio_id' => $tinte->id,
            'personal_id' => $ana->id,
            'fecha' => now()->toDateString(),
            'hora_inicio' => '15:00',
            'hora_fin' => '17:00',
            'estado' => 'confirmada',
            'notas' => 'Tono chocolate 6.7.',
        ]);
        PeluqueriaCita::query()->create([
            'empresa_id' => $empresaId,
            'cliente_id' => $diego->id,
            'servicio_id' => $barba->id,
            'personal_id' => $carlos->id,
            'fecha' => now()->addDay()->toDateString(),
            'hora_inicio' => '11:00',
            'hora_fin' => '12:00',
            'estado' => 'confirmada',
        ]);

        $pastCita = PeluqueriaCita::query()->create([
            'empresa_id' => $empresaId,
            'cliente_id' => $sofia->id,
            'servicio_id' => $corte->id,
            'personal_id' => $ana->id,
            'fecha' => now()->subDays(2)->toDateString(),
            'hora_inicio' => '10:00',
            'hora_fin' => '10:45',
            'estado' => 'finalizada',
        ]);
        $atencion = PeluqueriaAtencion::query()->create([
            'empresa_id' => $empresaId,
            'cita_id' => $pastCita->id,
            'cliente_id' => $sofia->id,
            'servicio_id' => $corte->id,
            'personal_id' => $ana->id,
            'estado' => 'finalizada',
            'iniciada_at' => now()->subDays(2)->setTime(10, 0),
            'finalizada_at' => now()->subDays(2)->setTime(10, 48),
            'precio_servicio' => 60,
            'descuento' => 0,
            'total' => 60,
            'observaciones' => 'Corte en capas y secado natural.',
        ]);
        PeluqueriaPago::query()->create([
            'empresa_id' => $empresaId,
            'atencion_id' => $atencion->id,
            'metodo' => 'qr',
            'monto' => 60,
            'referencia' => 'DEMO-QR-001',
            'pagado_at' => now()->subDays(2)->setTime(10, 50),
        ]);

        unset($todayCita);
    }
}
