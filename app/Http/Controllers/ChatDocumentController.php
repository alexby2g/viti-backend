<?php

namespace App\Http\Controllers;

use App\Models\{Conversacion,Mensaje,Usuario};
use App\Services\ChatChannelService;
use App\Support\{Audit,FirebasePush};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ChatDocumentController extends Controller
{
    private const MAX_KB = 10240;

    public function __construct(private ChatChannelService $channels) {}

    public function store(Request $request, Conversacion $conversacion): JsonResponse
    {
        $mode = (string)$request->route('chat_access');
        $this->authorize($request,$conversacion,$mode);

        $data = $request->validate([
            'mensaje'=>['nullable','string','max:5000'],
            'archivo'=>['required','file','mimes:pdf,doc,docx,xls,xlsx,txt','max:'.self::MAX_KB],
        ], [
            'archivo.required'=>'Selecciona un documento.',
            'archivo.file'=>'El adjunto debe ser un archivo válido.',
            'archivo.mimes'=>'Puedes enviar PDF, Word, Excel o TXT.',
            'archivo.max'=>'El documento no puede superar 10 MB.',
        ]);

        $file = $request->file('archivo');
        $context = $conversacion->contexto ?: ChatChannelService::VITI;
        $path = $file->store('chat/'.$context.'/'.now()->format('Y/m'),'private_uploads');
        abort_unless($path,500,'No pudimos guardar el documento adjunto.');

        try {
            $message = Mensaje::create([
                'conversacion_id'=>$conversacion->id,
                'usuario_id'=>$request->user()->id,
                'tipo'=>'archivo',
                'mensaje'=>trim((string)($data['mensaje'] ?? '')),
                'archivo_path'=>$path,
                'archivo_nombre'=>$file->getClientOriginalName(),
                'archivo_mime'=>$file->getMimeType(),
                'archivo_tamano'=>$file->getSize(),
            ]);
        } catch (\Throwable $e) {
            try { Storage::disk('private_uploads')->delete($path); } catch (\Throwable) {}
            throw $e;
        }

        if ($conversacion->estado === 'cerrada') $conversacion->update(['estado'=>'abierta']);
        $conversacion->update(['ultimo_mensaje_at'=>now()]);
        Audit::log($request,'documento_chat_enviado',$conversacion,'Se adjuntó '.$file->getClientOriginalName().' en '.$this->channels->label($context).'.');
        $this->push($request,$conversacion,'📎 '.$file->getClientOriginalName());

        return response()->json(['data'=>$message->fresh()->load('usuario:id,nombre,apellido,rol')],201);
    }

    private function authorize(Request $request, Conversacion $conversation, string $mode): void
    {
        switch ($mode) {
            case 'viti-client':
                $this->channels->assertClient($request,$conversation,ChatChannelService::VITI);
                return;
            case 'viti-admin':
                abort_unless($request->user()?->rol === 'superadmin',403,'Este buzón privado solo puede ser atendido por el superadministrador.');
                $this->channels->assertContext($conversation,ChatChannelService::VITI);
                return;
            case 'electro-business':
                $this->channels->assertBusiness($request,$conversation);
                return;
            case 'electro-customer':
                $this->channels->assertCustomer($request,$conversation);
                return;
            case 'electro-admin':
                abort_unless($request->user()?->isPlatformAdmin(),403,'No tienes permiso para adjuntar documentos aquí.');
                $this->channels->assertContext($conversation,ChatChannelService::ELECTROFRIO);
                return;
            default:
                abort(404,'El canal solicitado no existe.');
        }
    }

    private function push(Request $request, Conversacion $conversation, string $text): void
    {
        if ($conversation->contexto===ChatChannelService::ELECTROFRIO) {
            if($request->user()->rol==='cliente_negocio'){
                $targetIds=$this->channels->businessUserIds($conversation);
                $title='Nuevo documento de '.($request->user()->nombre?:'Cliente').' · Electrofrío';
                $path=$this->channels->businessPath($conversation).'?c='.$conversation->id;
            }else{
                $targetIds=Usuario::query()->where('estado','activo')->where('rol','cliente_negocio')->where('electrofrio_cliente_id',$conversation->electrofrio_cliente_id)->pluck('id')->all();
                $title='Nuevo documento de Electrofrío';
                $path=$this->channels->clientPath($conversation).'?c='.$conversation->id;
            }
        } elseif ($request->user()->rol === 'cliente') {
            $targetIds=$conversation->responsable_usuario_id
                ? [$conversation->responsable_usuario_id]
                : Usuario::query()->where('estado','activo')->whereIn('rol',['superadmin','administrador'])->pluck('id')->all();
            $title='Nuevo documento de '.($request->user()->nombre?:'Cliente').' · Atención VITI';
            $path=$this->channels->adminPath($conversation).'?c='.$conversation->id;
        } else {
            $targetIds=Usuario::query()->where('estado','activo')->where('rol','cliente')->where('cliente_id',$conversation->cliente_id)->pluck('id')->all();
            $title='Nuevo documento de Atención VITI';
            $path=$this->channels->clientPath($conversation).'?c='.$conversation->id;
        }

        FirebasePush::sendToUsers($targetIds,$title,$text,[
            'type'=>'buzon',
            'contexto'=>$conversation->contexto,
            'conversation_id'=>$conversation->id,
            'path'=>$path,
        ]);
    }
}
