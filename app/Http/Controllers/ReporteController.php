<?php

namespace App\Http\Controllers;

use App\Models\{Cliente,Empresa,Proyecto,SolicitudSistema};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    public function clientes(): Response
    {
        $clientes = Cliente::with('empresas:id,cliente_id,nombre_comercial')->withCount(['empresas','solicitudes','proyectos'])->latest('id')->get();
        return Pdf::loadView('reports.clientes', compact('clientes'))->setPaper('a4','landscape')->download('viti-clientes.pdf');
    }

    public function empresas(): Response
    {
        $empresas = Empresa::with('cliente')->withCount(['solicitudes','proyectos','aplicaciones'])->latest()->get();
        return Pdf::loadView('reports.empresas', compact('empresas'))->setPaper('a4','landscape')->download('viti-empresas.pdf');
    }

    public function solicitud(SolicitudSistema $solicitud): Response
    {
        $solicitud->load(['empresa','cliente','planViti','cuestionario.secciones.preguntas','respuestas.pregunta']);
        $answers = $solicitud->respuestas->keyBy('pregunta_id');
        return Pdf::loadView('reports.solicitud', compact('solicitud','answers'))->setPaper('a4')->download($solicitud->codigo.'-cuestionario.pdf');
    }

    public function clienteSolicitud(Request $request, SolicitudSistema $solicitud): Response
    {
        abort_unless((int)$solicitud->cliente_id === (int)$request->user()->cliente_id, 403, 'No tienes permiso para descargar este documento.');
        $solicitud->load(['empresa','cliente','planViti','cuestionario.secciones.preguntas','respuestas.pregunta']);
        $answers = $solicitud->respuestas->keyBy('pregunta_id');
        return Pdf::loadView('reports.solicitud', compact('solicitud','answers'))->setPaper('a4')->download($solicitud->codigo.'-cuestionario.pdf');
    }

    public function proyecto(Proyecto $proyecto): Response
    {
        $proyecto->load(['empresa','cliente','responsable','avances.creador','avances.archivos','aplicacion']);
        return Pdf::loadView('reports.proyecto', compact('proyecto'))->setPaper('a4')->download($proyecto->codigo.'-proyecto.pdf');
    }
}
