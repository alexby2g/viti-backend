<?php

namespace App\Http\Controllers;

use App\Models\{Cliente, Empresa};
use App\Support\{Audit, Code};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cliente::query()
            ->with(['empresas' => fn ($q) => $q->select('id','cliente_id','codigo','nombre_comercial','actividad','telefono','estado')->latest()])
            ->withCount(['empresas','solicitudes','proyectos'])
            ->latest('id');

        if ($request->filled('buscar')) {
            $term = '%'.$request->string('buscar').'%';
            $query->where(fn ($q) => $q->where('nombre','like',$term)
                ->orWhere('telefono','like',$term)
                ->orWhere('whatsapp','like',$term)
                ->orWhereHas('empresas', fn ($e) => $e->where('nombre_comercial','like',$term)));
        }

        return response()->json($query->paginate(min(max((int)$request->input('per_page',20),1),100)));
    }

    public function store(Request $request): JsonResponse
    {
        $cliente = Cliente::create($this->validateData($request));
        Audit::log($request,'cliente_creado',$cliente,'Se registró un cliente.');
        return response()->json(['data'=>$cliente],201);
    }

    public function storeComplete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cliente.nombre' => ['required','string','max:180'],
            'cliente.telefono' => ['required','string','max:30','unique:clientes,telefono'],
            'cliente.whatsapp' => ['nullable','string','max:30'],
            'cliente.documento' => ['nullable','string','max:50','unique:clientes,documento'],
            'cliente.ci_expedido' => ['nullable','string','max:20'],
            'cliente.ciudad' => ['nullable','string','max:100'],
            'cliente.direccion' => ['nullable','string','max:255'],
            'cliente.observaciones' => ['nullable','string','max:3000'],
            'cliente.estado' => ['nullable',Rule::in(['prospecto','activo','inactivo'])],
            'empresa' => ['nullable','array'],
            'empresa.nombre_comercial' => ['required_with:empresa','nullable','string','max:180'],
            'empresa.actividad' => ['nullable','string','max:200'],
            'empresa.telefono' => ['nullable','string','max:30'],
            'empresa.whatsapp' => ['nullable','string','max:30'],
            'empresa.ciudad' => ['nullable','string','max:100'],
            'empresa.direccion' => ['nullable','string','max:255'],
            'empresa.observaciones' => ['nullable','string','max:3000'],
        ], [
            'cliente.telefono.unique' => 'Ese teléfono ya pertenece a otro cliente.',
            'cliente.documento.unique' => 'Esa cédula de identidad ya pertenece a otro cliente.',
            'empresa.nombre_comercial.required_with' => 'Escribe el nombre de la empresa o microempresa.',
        ]);

        [$cliente, $empresa] = DB::transaction(function () use ($data) {
            $cliente = Cliente::create($data['cliente']);
            $empresa = null;
            if (!empty($data['empresa']['nombre_comercial'])) {
                $empresaData = $data['empresa'];
                $empresaData['cliente_id'] = $cliente->id;
                $empresaData['codigo'] = Code::next('empresas','EMP');
                $empresaData['estado'] = 'prospecto';
                $empresa = Empresa::create($empresaData);
            }
            return [$cliente, $empresa];
        });

        Audit::log($request,'cliente_creado',$cliente,'Se registró un cliente con su empresa desde el flujo unificado.');
        if ($empresa) Audit::log($request,'empresa_creada',$empresa,'Se registró la empresa del cliente.');

        return response()->json(['data'=>[
            'cliente'=>$cliente->fresh()->load('empresas'),
            'empresa'=>$empresa,
        ]],201);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        return response()->json(['data'=>$cliente->load(['empresas','solicitudes','proyectos','archivos'])]);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $cliente->update($this->validateData($request,$cliente));
        Audit::log($request,'cliente_actualizado',$cliente,'Se actualizaron los datos del cliente.');
        return response()->json(['data'=>$cliente->fresh()->load('empresas')]);
    }

    public function verifyPhoto(Request $request, Cliente $cliente): JsonResponse
    {
        abort_unless($cliente->foto_path, 422, 'El cliente todavía no tiene una fotografía de perfil.');
        $cliente->update(['foto_verificada'=>!$cliente->foto_verificada]);
        Audit::log($request,'foto_cliente_verificada',$cliente,$cliente->foto_verificada?'Se verificó la fotografía del cliente.':'Se retiró la verificación de la fotografía.');
        return response()->json(['data'=>$cliente->fresh()]);
    }

    public function destroy(Request $request, Cliente $cliente): JsonResponse
    {
        abort_if($cliente->empresas()->exists()||$cliente->solicitudes()->exists(),422,'No se puede eliminar un cliente con empresas o solicitudes.');
        $cliente->delete();
        Audit::log($request,'cliente_eliminado',$cliente,'Cliente enviado a papelera.');
        return response()->json(status:204);
    }

    private function validateData(Request $request, ?Cliente $cliente=null): array
    {
        return $request->validate([
            'nombre'=>['required','string','max:180'],
            'telefono'=>['required','string','max:30',Rule::unique('clientes','telefono')->ignore($cliente?->id)],
            'whatsapp'=>['nullable','string','max:30'],
            'documento'=>['nullable','string','max:50',Rule::unique('clientes','documento')->ignore($cliente?->id)],
            'ci_expedido'=>['nullable','string','max:20'],
            'ciudad'=>['nullable','string','max:100'],
            'direccion'=>['nullable','string','max:255'],
            'observaciones'=>['nullable','string','max:3000'],
            'estado'=>['nullable',Rule::in(['prospecto','activo','inactivo'])],
        ], ['telefono.unique'=>'Ese teléfono ya pertenece a otro cliente.','documento.unique'=>'Esa cédula de identidad ya pertenece a otro cliente.']);
    }
}
