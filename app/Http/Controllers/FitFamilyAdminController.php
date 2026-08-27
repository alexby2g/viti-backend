<?php

namespace App\Http\Controllers;

use App\Models\{Aplicacion, FitFamilyCategoria, FitFamilyProducto};
use App\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FitFamilyAdminController extends Controller
{
    public function __construct(private TenantContext $tenant) {}

    public function categories(Request $request): JsonResponse
    {
        $empresa = $this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(), $empresa);
        $application = $this->application($request, $empresa);
        $categories = FitFamilyCategoria::query()->where('empresa_id', $empresa->id)->where('aplicacion_id', $application->id)->orderBy('orden')->orderBy('nombre')->get();
        return response()->json(['data' => $categories]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $empresa = $this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(), $empresa);
        $application = $this->application($request, $empresa);
        $data = $request->validate(['nombre'=>['required','string','min:2','max:120'],'descripcion'=>['nullable','string','max:5000'],'activo'=>['sometimes','boolean'],'orden'=>['sometimes','integer','min:0','max:999999']]);
        $category = FitFamilyCategoria::create([...$data,'empresa_id'=>$empresa->id,'aplicacion_id'=>$application->id,'slug'=>$this->uniqueSlug(FitFamilyCategoria::class,$data['nombre'],$application->id)]);
        return response()->json(['data'=>$category],201);
    }

    public function updateCategory(Request $request, FitFamilyCategoria $category): JsonResponse
    {
        $empresa = $this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(), $empresa);
        $application = $this->application($request, $empresa);
        abort_unless((int)$category->empresa_id === (int)$empresa->id && (int)$category->aplicacion_id === (int)$application->id,404);
        $data = $request->validate(['nombre'=>['required','string','min:2','max:120'],'descripcion'=>['nullable','string','max:5000'],'activo'=>['sometimes','boolean'],'orden'=>['sometimes','integer','min:0','max:999999']]);
        if ($data['nombre'] !== $category->nombre) $data['slug'] = $this->uniqueSlug(FitFamilyCategoria::class,$data['nombre'],$application->id,(int)$category->id);
        $category->update($data);
        return response()->json(['data'=>$category->fresh()]);
    }

    public function products(Request $request): JsonResponse
    {
        $empresa = $this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(), $empresa);
        $application = $this->application($request, $empresa);
        $query = FitFamilyProducto::query()->with('categoria:id,nombre,slug')->where('empresa_id',$empresa->id)->where('aplicacion_id',$application->id)->when($request->filled('categoria_id'),fn($q)=>$q->where('categoria_id',(int)$request->integer('categoria_id')))->when($request->filled('q'),fn($q)=>$q->where('nombre','like','%'.trim((string)$request->input('q')).'%'))->orderBy('nombre');
        return response()->json(['data'=>$query->paginate(min(100,max(1,(int)$request->input('per_page',20))))]);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $empresa=$this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(),$empresa);
        $application=$this->application($request,$empresa);
        $data=$request->validate(['categoria_id'=>['nullable','integer'],'nombre'=>['required','string','min:2','max:180'],'descripcion'=>['nullable','string','max:10000'],'imagen_url'=>['nullable','url','max:1000'],'precio'=>['required','numeric','min:0','max:9999999999.99'],'stock'=>['sometimes','integer','min:0','max:2147483647'],'disponible'=>['sometimes','boolean'],'visible_catalogo'=>['sometimes','boolean'],'datos_nutricionales'=>['nullable','array']]);
        $this->assertCategoryBelongsToApplication($data['categoria_id']??null,$empresa->id,$application->id);
        $product=FitFamilyProducto::create([...$data,'empresa_id'=>$empresa->id,'aplicacion_id'=>$application->id,'slug'=>$this->uniqueSlug(FitFamilyProducto::class,$data['nombre'],$application->id)]);
        return response()->json(['data'=>$product->load('categoria')],201);
    }

    public function updateProduct(Request $request, FitFamilyProducto $product): JsonResponse
    {
        $empresa=$this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(),$empresa);
        $application=$this->application($request,$empresa);
        $this->assertProductBelongsToApplication($product,$empresa->id,$application->id);
        $data=$request->validate(['categoria_id'=>['nullable','integer'],'nombre'=>['required','string','min:2','max:180'],'descripcion'=>['nullable','string','max:10000'],'imagen_url'=>['nullable','url','max:1000'],'precio'=>['required','numeric','min:0','max:9999999999.99'],'stock'=>['sometimes','integer','min:0','max:2147483647'],'disponible'=>['sometimes','boolean'],'visible_catalogo'=>['sometimes','boolean'],'datos_nutricionales'=>['nullable','array']]);
        $this->assertCategoryBelongsToApplication($data['categoria_id']??null,$empresa->id,$application->id);
        if ($data['nombre'] !== $product->nombre) $data['slug']=$this->uniqueSlug(FitFamilyProducto::class,$data['nombre'],$application->id,(int)$product->id);
        $product->update($data);
        return response()->json(['data'=>$product->fresh()->load('categoria')]);
    }

    public function updateStock(Request $request, FitFamilyProducto $product): JsonResponse
    {
        $empresa=$this->tenant->resolve($request);
        $this->tenant->assertCanManage($request->user(),$empresa);
        $application=$this->application($request,$empresa);
        $this->assertProductBelongsToApplication($product,$empresa->id,$application->id);
        $data=$request->validate(['stock'=>['required','integer','min:0','max:2147483647'],'disponible'=>['sometimes','boolean']]);
        $product->update($data);
        return response()->json(['data'=>$product->fresh()]);
    }

    private function assertCategoryBelongsToApplication(?int $categoryId,int $empresaId,int $applicationId):void
    {
        if($categoryId===null)return;
        $valid=FitFamilyCategoria::query()->whereKey($categoryId)->where('empresa_id',$empresaId)->where('aplicacion_id',$applicationId)->exists();
        abort_unless($valid,422,'La categoría no pertenece a la aplicación FitFamily seleccionada.');
    }

    private function assertProductBelongsToApplication(FitFamilyProducto $product,int $empresaId,int $applicationId):void
    {
        abort_unless((int)$product->empresa_id===$empresaId&&(int)$product->aplicacion_id===$applicationId,404);
    }

    private function application(Request $request,$empresa):Aplicacion
    {
        $id=$request->integer('aplicacion_id');
        abort_unless($id>0,422,'Selecciona la aplicación FitFamily activa.');
        $application=$empresa->aplicaciones()->with('catalogo')->whereKey($id)->first();
        abort_unless($application,403,'No tienes acceso a esa aplicación.');
        $name=Str::lower((string)($application->nombre??''));
        $slug=Str::lower((string)($application->slug??''));
        $catalogName=Str::lower((string)($application->catalogo?->nombre??''));
        abort_unless(Str::contains($name,'fitfamily')||Str::contains($slug,'fitfamily')||Str::contains($catalogName,'fitfamily'),422,'La aplicación seleccionada no corresponde a FitFamily.');
        return $application;
    }

    private function uniqueSlug(string $model,string $name,int $applicationId,?int $ignoreId=null):string
    {
        $base=Str::slug($name)?:'item'; $slug=$base; $counter=2;
        while($model::query()->where('aplicacion_id',$applicationId)->when($ignoreId!==null,fn($q)=>$q->where('id','!=',$ignoreId))->where('slug',$slug)->exists()) $slug=$base.'-'.$counter++;
        return $slug;
    }
}
