<?php

namespace App\Http\Controllers;

use App\Models\{Cuestionario,CuestionarioPregunta,CuestionarioSeccion};
use Illuminate\Http\{JsonResponse,Request};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CuestionarioController extends Controller
{
    private const TYPES=['texto','numero','seleccion_unica','seleccion_multiple'];

    public function index(): JsonResponse
    {
        $rows=Cuestionario::query()
            ->withCount(['seccionesTodas as secciones_count','solicitudes'])
            ->orderByDesc('activo')->orderByDesc('id')->get();
        return response()->json(['data'=>$rows]);
    }

    public function show(Cuestionario $cuestionario): JsonResponse
    {
        return response()->json(['data'=>$this->detail($cuestionario)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data=$request->validate([
            'nombre'=>['required','string','min:3','max:180'],
            'version'=>['nullable','string','max:30'],
            'descripcion'=>['nullable','string','max:3000'],
            'activo'=>['sometimes','boolean'],
        ]);
        $data['version']=$data['version']??'1.0';
        $data['activo']=$data['activo']??false;
        $row=DB::transaction(function()use($data){
            if($data['activo'])Cuestionario::query()->update(['activo'=>false]);
            return Cuestionario::create($data);
        });
        return response()->json(['data'=>$this->detail($row)],201);
    }

    public function update(Request $request, Cuestionario $cuestionario): JsonResponse
    {
        $data=$request->validate([
            'nombre'=>['sometimes','required','string','min:3','max:180'],
            'version'=>['sometimes','nullable','string','max:30'],
            'descripcion'=>['sometimes','nullable','string','max:3000'],
            'activo'=>['sometimes','boolean'],
        ]);
        DB::transaction(function()use($data,$cuestionario){
            if(($data['activo']??false)===true)Cuestionario::query()->where('id','!=',$cuestionario->id)->update(['activo'=>false]);
            $cuestionario->update($data);
        });
        return response()->json(['data'=>$this->detail($cuestionario->fresh())]);
    }

    public function destroy(Cuestionario $cuestionario): JsonResponse
    {
        if($cuestionario->solicitudes()->exists())throw ValidationException::withMessages(['cuestionario'=>'Este formulario ya fue usado en solicitudes. Desactívalo en lugar de eliminarlo.']);
        $cuestionario->delete();
        return response()->json(['message'=>'Formulario eliminado.']);
    }

    public function storeSection(Request $request, Cuestionario $cuestionario): JsonResponse
    {
        $data=$request->validate([
            'titulo'=>['required','string','min:2','max:180'],
            'descripcion'=>['nullable','string','max:2000'],
            'activo'=>['sometimes','boolean'],
            'orden'=>['nullable','integer','min:1','max:999'],
        ]);
        $next=(int)($cuestionario->seccionesTodas()->max('numero')??0)+1;
        $order=(int)($data['orden']??(($cuestionario->seccionesTodas()->max('orden')??0)+1));
        $section=$cuestionario->seccionesTodas()->create([
            'numero'=>$next,'titulo'=>$data['titulo'],'descripcion'=>$data['descripcion']??null,
            'activo'=>$data['activo']??true,'orden'=>$order,
        ]);
        return response()->json(['data'=>$section],201);
    }

    public function updateSection(Request $request, CuestionarioSeccion $seccion): JsonResponse
    {
        $data=$request->validate([
            'titulo'=>['sometimes','required','string','min:2','max:180'],
            'descripcion'=>['sometimes','nullable','string','max:2000'],
            'activo'=>['sometimes','boolean'],
            'orden'=>['sometimes','integer','min:1','max:999'],
        ]);
        $seccion->update($data);
        return response()->json(['data'=>$seccion->fresh()]);
    }

    public function destroySection(CuestionarioSeccion $seccion): JsonResponse
    {
        $hasAnswers=CuestionarioPregunta::query()->where('seccion_id',$seccion->id)->whereHas('respuestas')->exists();
        if($hasAnswers)throw ValidationException::withMessages(['seccion'=>'Esta sección contiene preguntas con respuestas históricas. Desactívala en lugar de eliminarla.']);
        $seccion->delete();
        return response()->json(['message'=>'Sección eliminada.']);
    }

    public function storeQuestion(Request $request, CuestionarioSeccion $seccion): JsonResponse
    {
        $data=$this->questionData($request);
        $next=(int)(CuestionarioPregunta::query()->max('numero')??0)+1;
        $order=(int)($data['orden']??(($seccion->preguntasTodas()->max('orden')??0)+1));
        unset($data['orden']);
        $row=$seccion->preguntasTodas()->create($data+['numero'=>$next,'orden'=>$order]);
        return response()->json(['data'=>$row],201);
    }

    public function updateQuestion(Request $request, CuestionarioPregunta $pregunta): JsonResponse
    {
        $data=$this->questionData($request,true);
        if($pregunta->respuestas()->exists()){
            $semantic=['pregunta','tipo','opciones','obligatoria'];
            foreach($semantic as $field){
                if(array_key_exists($field,$data) && $this->changed($pregunta,$field,$data[$field])){
                    throw ValidationException::withMessages(['pregunta'=>'Esta pregunta ya tiene respuestas. Para cambiar su texto, tipo u opciones, duplícala y desactiva la original.']);
                }
            }
        }
        $pregunta->update($data);
        return response()->json(['data'=>$pregunta->fresh()->loadCount('respuestas')]);
    }

    public function duplicateQuestion(CuestionarioPregunta $pregunta): JsonResponse
    {
        $copy=$pregunta->replicate(['numero','orden','created_at','updated_at']);
        $copy->numero=(int)(CuestionarioPregunta::query()->max('numero')??0)+1;
        $copy->orden=(int)($pregunta->seccion->preguntasTodas()->max('orden')??0)+1;
        $copy->pregunta=trim($pregunta->pregunta).' (copia)';
        $copy->activo=true;
        $copy->save();
        return response()->json(['data'=>$copy->loadCount('respuestas')],201);
    }

    public function destroyQuestion(CuestionarioPregunta $pregunta): JsonResponse
    {
        if($pregunta->respuestas()->exists())throw ValidationException::withMessages(['pregunta'=>'Esta pregunta ya tiene respuestas. Desactívala en lugar de eliminarla.']);
        $pregunta->delete();
        return response()->json(['message'=>'Pregunta eliminada.']);
    }

    private function questionData(Request $request,bool $partial=false): array
    {
        $prefix=$partial?'sometimes':'required';
        $data=$request->validate([
            'pregunta'=>[$prefix,'string','min:2','max:2000'],
            'tipo'=>[$prefix,Rule::in(self::TYPES)],
            'opciones'=>['nullable','array','max:50'],
            'opciones.*'=>['string','max:300'],
            'ayuda'=>['nullable','string','max:2000'],
            'obligatoria'=>['sometimes','boolean'],
            'activo'=>['sometimes','boolean'],
            'orden'=>['sometimes','integer','min:1','max:999'],
        ]);
        if(array_key_exists('tipo',$data) && !in_array($data['tipo'],['seleccion_unica','seleccion_multiple'],true))$data['opciones']=null;
        if(isset($data['opciones']))$data['opciones']=array_values(array_filter(array_map('trim',$data['opciones']),fn($v)=>$v!==''));
        if(!$partial){$data['obligatoria']=$data['obligatoria']??false;$data['activo']=$data['activo']??true;}
        return $data;
    }

    private function changed(CuestionarioPregunta $pregunta,string $field,mixed $value): bool
    {
        $old=$pregunta->{$field};
        if($field==='opciones')return array_values($old??[])!==array_values($value??[]);
        if($field==='obligatoria')return (bool)$old!==(bool)$value;
        return (string)($old??'')!==(string)($value??'');
    }

    private function detail(Cuestionario $cuestionario): array
    {
        $cuestionario->loadCount('solicitudes');
        $sections=$cuestionario->seccionesTodas()->with(['preguntasTodas'=>fn($q)=>$q->withCount('respuestas')->orderBy('orden')])->orderBy('orden')->get();
        $data=$cuestionario->toArray();
        $data['secciones']=$sections->map(function($section){
            $row=$section->toArray();
            $row['preguntas']=$section->preguntasTodas->values()->all();
            unset($row['preguntas_todas']);
            return $row;
        })->values()->all();
        return $data;
    }
}
