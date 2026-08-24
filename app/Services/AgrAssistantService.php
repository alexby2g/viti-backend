<?php

namespace App\Services;

use App\Models\{Aplicacion, Cliente, Empresa, Mantenimiento, Proyecto, SolicitudSistema, Suscripcion};
use Illuminate\Support\Str;

class AgrAssistantService
{
    public function handle(string $input): array
    {
        $text = $this->normalize($input);

        if ($text === '') return $this->helpResponse();
        if ($this->matches($text, ['ayuda', 'que puedes hacer', 'comandos', 'opciones'])) return $this->helpResponse();
        if ($this->matches($text, ['resumen', 'resumen de viti', 'estado de viti', 'como esta viti', 'como va viti'])) return $this->summaryResponse($text);

        if ($this->matches($text, ['abrir clientes', 'ir a clientes', 'mostrar clientes'])) return $this->navigationResponse('clients', $this->reply('open_clients', $text), '/empresas');
        if ($this->matches($text, ['abrir empresas', 'ir a empresas', 'mostrar empresas'])) return $this->navigationResponse('companies', $this->reply('open_companies', $text), '/empresas');
        if ($this->matches($text, ['abrir solicitudes', 'ir a solicitudes', 'mostrar solicitudes'])) return $this->navigationResponse('requests', $this->reply('open_requests', $text), '/solicitudes');
        if ($this->matches($text, ['abrir proyectos', 'ir a proyectos', 'mostrar proyectos'])) return $this->navigationResponse('projects', $this->reply('open_projects', $text), '/proyectos');
        if ($this->matches($text, ['abrir pagos', 'ir a pagos', 'mostrar pagos'])) return $this->navigationResponse('payments', $this->reply('open_payments', $text), '/pagos');
        if ($this->matches($text, ['abrir soportes', 'ir a soportes', 'mostrar soportes', 'abrir mantenimiento'])) return $this->navigationResponse('support', $this->reply('open_support', $text), '/mantenimientos');

        if ($this->matches($text, ['crear cliente', 'registrar cliente', 'nuevo cliente'])) return $this->confirmAction('create_client', $this->reply('create_client', $text), ['action'=>['type'=>'form','target'=>'client_create','confirm_required'=>false]]);
        if ($this->matches($text, ['crear empresa', 'registrar empresa', 'nueva empresa'])) return $this->confirmAction('create_company', $this->reply('create_company', $text), ['action'=>['type'=>'form','target'=>'company_create','confirm_required'=>false]]);
        if ($this->matches($text, ['crear solicitud', 'nueva solicitud'])) return $this->confirmAction('create_request', $this->reply('create_request', $text), ['action'=>['type'=>'form','target'=>'request_create','confirm_required'=>true]]);
        if ($this->matches($text, ['resolver soporte', 'cerrar soporte', 'marcar soporte resuelto'])) return $this->confirmAction('resolve_support', $this->reply('resolve_support', $text), ['action'=>['type'=>'confirmation','target'=>'support_resolve','confirm_required'=>true]]);

        if ($this->matches($text, ['cuantos clientes', 'cantidad de clientes', 'total de clientes', 'numero de clientes'])) {
            $count = Cliente::query()->count();
            return $this->countResponse('count_clients', $this->reply('count_clients', $text, $count), $count);
        }
        if ($this->matches($text, ['cuantas empresas', 'cantidad de empresas', 'total de empresas', 'numero de empresas'])) {
            $count = Empresa::query()->count();
            return $this->countResponse('count_companies', $this->reply('count_companies', $text, $count), $count);
        }
        if ($this->matches($text, ['solicitudes pendientes', 'solicitudes sin resolver', 'cuantas solicitudes pendientes'])) {
            $count = SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count();
            return $this->countResponse('pending_requests', $this->reply('pending_requests', $text, $count), $count);
        }
        if ($this->matches($text, ['cuantos proyectos', 'cantidad de proyectos', 'total de proyectos'])) {
            $count = Proyecto::query()->count();
            return $this->countResponse('count_projects', $this->reply('count_projects', $text, $count), $count);
        }
        if ($this->matches($text, ['proyectos activos', 'proyectos en curso', 'cuantos proyectos activos'])) {
            $count = Proyecto::query()->where('estado', 'activo')->count();
            return $this->countResponse('active_projects', $this->reply('active_projects', $text, $count), $count);
        }
        if ($this->matches($text, ['aplicaciones activas', 'apps activas', 'cuantas aplicaciones activas', 'cuantas apps activas'])) {
            $count = Aplicacion::query()->count();
            return $this->countResponse('active_apps', $this->reply('active_apps', $text, $count), $count);
        }
        if ($this->matches($text, ['pagos vencidos', 'suscripciones vencidas', 'cuantos pagos vencidos', 'pagos con atencion'])) {
            $count = Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count();
            return $this->countResponse('overdue_payments', $this->reply('overdue_payments', $text, $count), $count);
        }
        if ($this->matches($text, ['soportes abiertos', 'mantenimientos abiertos', 'cuantos soportes abiertos'])) {
            $count = Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count();
            return $this->countResponse('open_support', $this->reply('open_support', $text, $count), $count);
        }

        if (preg_match('/^(buscar|busca|encuentra|mostrar|muestrame|muéstrame)\s+(al\s+)?cliente\s+(.+)$/u', $text, $matches)) return $this->searchClient($matches[3], $text);
        if (preg_match('/^(buscar|busca|encuentra|mostrar|muestrame|muéstrame)\s+(la\s+|la\s+empresa\s+|empresa\s+)(.+)$/u', $text, $matches)) return $this->searchCompany($matches[4], $text);
        if (str_contains($text, 'cliente') && preg_match('/cliente\s+(.+)/u', $text, $matches)) return $this->searchClient($matches[1], $text);
        if (str_contains($text, 'empresa') && preg_match('/empresa\s+(.+)/u', $text, $matches)) return $this->searchCompany($matches[1], $text);

        return [
            'intent' => 'unknown',
            'message' => $this->reply('unknown', $text),
            'data' => [],
        ];
    }

    private function summaryResponse(string $text): array
    {
        $clients = Cliente::query()->count();
        $companies = Empresa::query()->where('estado', 'activo')->count();
        $requests = SolicitudSistema::query()->whereNotIn('estado', ['completada', 'rechazada', 'cancelada'])->count();
        $projects = Proyecto::query()->where('estado', 'activo')->count();
        $payments = Suscripcion::query()->whereIn('estado', ['gracia', 'suspendida'])->count();
        $support = Mantenimiento::query()->whereNotIn('estado', ['resuelto', 'cerrado'])->count();

        return [
            'intent'=>'summary',
            'message'=>$this->reply('summary', $text, null, compact('clients','companies','requests','projects','payments','support')),
            'data'=>compact('clients','companies','requests','projects','payments','support'),
            'meta'=>['style'=>'contextual','source'=>'viti_local']
        ];
    }

    private function countResponse(string $intent, string $message, int $count): array
    {
        return ['intent'=>$intent,'message'=>$message,'data'=>['count'=>$count],'meta'=>['style'=>'contextual','source'=>'viti_local']];
    }

    private function navigationResponse(string $intent, string $message, string $to): array
    {
        return ['intent'=>'navigate_'.$intent,'message'=>$message,'data'=>['action'=>['type'=>'navigate','to'=>$to,'label'=>'Abrir']],'meta'=>['style'=>'action_oriented']];
    }

    private function confirmAction(string $intent, string $message, array $action): array
    {
        return ['intent'=>$intent,'message'=>$message,'data'=>$action,'meta'=>['style'=>'safe_action','confirm_required'=>$action['action']['confirm_required'] ?? true]];
    }

    private function searchClient(string $name, string $text): array
    {
        $name = $this->cleanSearchTerm($name);
        if ($name === '') return ['intent'=>'search_client','message'=>$this->reply('search_client_empty',$text),'data'=>['results'=>[]]];
        $clients = Cliente::query()->where(function ($query) use ($name) { $query->where('nombre','like','%'.$name.'%')->orWhere('telefono','like','%'.$name.'%')->orWhere('correo','like','%'.$name.'%'); })->limit(8)->get(['id','nombre','telefono','correo','estado']);
        if ($clients->isEmpty()) return ['intent'=>'search_client','message'=>$this->reply('search_client_none',$text, null, ['term'=>$name]),'data'=>['results'=>[]]];
        return ['intent'=>'search_client','message'=>$this->reply('search_client_found',$text, $clients->count(), ['term'=>$name]),'data'=>['results'=>$clients->values()->all()]];
    }

    private function searchCompany(string $name, string $text): array
    {
        $name = $this->cleanSearchTerm($name);
        if ($name === '') return ['intent'=>'search_company','message'=>$this->reply('search_company_empty',$text),'data'=>['results'=>[]]];
        $companies = Empresa::query()->where(function ($query) use ($name) { $query->where('nombre_comercial','like','%'.$name.'%')->orWhere('razon_social','like','%'.$name.'%')->orWhere('codigo','like','%'.$name.'%'); })->limit(8)->get(['id','codigo','nombre_comercial','razon_social','estado','telefono','ciudad']);
        if ($companies->isEmpty()) return ['intent'=>'search_company','message'=>$this->reply('search_company_none',$text, null, ['term'=>$name]),'data'=>['results'=>[]]];
        return ['intent'=>'search_company','message'=>$this->reply('search_company_found',$text, $companies->count(), ['term'=>$name]),'data'=>['results'=>$companies->values()->all()]];
    }

    private function helpResponse(): array
    {
        return [
            'intent'=>'help',
            'message'=>$this->reply('help','help'),
            'data'=>['commands'=>['Resumen','Abrir clientes','Crear cliente','Crear empresa','Crear solicitud','Resolver soporte','Cuántos clientes tengo','Proyectos activos','Pagos vencidos','Soportes abiertos','Buscar cliente Juan Pérez','Buscar empresa Sahory']],
            'meta'=>['style'=>'guided']
        ];
    }

    private function reply(string $key, string $context, ?int $count = null, array $data = []): string
    {
        $bank = [
            'summary' => [
                'Tengo una lectura rápida de VITI: {clients} clientes, {companies} empresas activas, {requests} solicitudes pendientes, {projects} proyectos activos, {payments} cuentas con atención y {support} soportes abiertos.',
                'Así está VITI ahora mismo: {clients} clientes, {companies} empresas activas y {projects} proyectos en curso. Además, hay {requests} solicitudes pendientes, {payments} pagos que requieren atención y {support} soportes abiertos.',
                'Panorama operativo de VITI: {companies} negocios activos, {clients} clientes registrados y {projects} proyectos activos. Lo que pide atención hoy: {requests} solicitudes, {payments} pagos y {support} soportes.'
            ],
            'count_clients' => ['VITI tiene {count} clientes registrados.','Detecté {count} clientes en la base actual de VITI.','El registro actual suma {count} clientes.'],
            'count_companies' => ['Hay {count} empresas registradas en VITI.','La plataforma tiene {count} empresas en su registro.','En este momento, VITI contabiliza {count} empresas.'],
            'pending_requests' => ['Hay {count} solicitudes que todavía necesitan atención.','Ahora mismo quedan {count} solicitudes pendientes.','Veo {count} solicitudes abiertas que conviene revisar.'],
            'count_projects' => ['VITI registra {count} proyectos.','Tengo {count} proyectos almacenados en VITI.','El sistema cuenta con {count} proyectos registrados.'],
            'active_projects' => ['Hay {count} proyectos activos en curso.','Ahora mismo hay {count} proyectos moviéndose.','Detecto {count} proyectos con estado activo.'],
            'active_apps' => ['Hay {count} aplicaciones registradas en VITI.','VITI tiene {count} aplicaciones disponibles en su ecosistema.','La plataforma registra {count} aplicaciones.'],
            'overdue_payments' => ['Hay {count} suscripciones que requieren atención por pago.','Detecté {count} cuentas con estado de gracia o suspensión.','Hay {count} situaciones de pago que merecen revisión.'],
            'open_support' => ['Hay {count} soportes o mantenimientos abiertos.','Detecto {count} incidencias que todavía no figuran como resueltas.','Veo {count} atenciones técnicas pendientes de cierre.'],
            'open_clients' => ['Voy directo al módulo de clientes.','Te llevo al registro de clientes.','Abramos clientes; desde ahí podemos revisar o buscar registros.'],
            'open_companies' => ['Abro el módulo de empresas.','Te llevo al registro de negocios.','Vamos al espacio de empresas de VITI.'],
            'open_requests' => ['Te llevo a las solicitudes para que podamos revisarlas.','Abriendo solicitudes; ahí podemos ver lo que sigue pendiente.','Vamos al centro de solicitudes.'],
            'open_projects' => ['Abriendo proyectos para revisar el trabajo activo.','Te llevo al módulo de proyectos.','Vamos a proyectos; ahí podemos revisar avances y estado.'],
            'open_payments' => ['Te llevo al área de pagos.','Abriendo pagos para revisar las situaciones pendientes.','Vamos al módulo de pagos.'],
            'open_support' => ['Abriendo soporte y mantenimientos.','Te llevo al centro de soporte para revisar incidencias.','Vamos al módulo de mantenimientos.'],
            'create_client' => ['Claro. Podemos crear el cliente paso a paso y revisar los datos antes de guardar.','Perfecto. Prepararé el registro del nuevo cliente y te mostraré una confirmación antes de escribir en VITI.','Listo. Vamos a construir el registro del cliente sin tocar la base hasta que lo apruebes.'],
            'create_company' => ['Podemos preparar la nueva empresa y validar los datos antes de guardarla.','Perfecto. Vamos a construir el registro de la empresa y dejar la confirmación para el último paso.','Entendido. AGR preparará la empresa y no modificará VITI hasta que la confirmes.'],
            'create_request' => ['Puedo preparar la solicitud y dejarla lista para tu confirmación final.','Vamos a construir la solicitud primero; el guardado quedará bloqueado hasta que la apruebes.','Perfecto. Prepararé la solicitud sin escribir nada hasta recibir tu confirmación.'],
            'resolve_support' => ['Puedo preparar el cierre del soporte, pero el cambio quedará bloqueado hasta que lo confirmes.','Entendido. AGR identificará la operación y pedirá autorización antes de modificar el estado.','Podemos cerrar ese soporte de forma segura: primero preparamos, después confirmamos y recién ahí guardamos.'],
            'search_client_empty' => ['Dime al menos un nombre, teléfono o correo y lo busco.','Necesito un dato del cliente para localizarlo.','Pásame un nombre, teléfono o correo y hago la búsqueda.'],
            'search_client_none' => ['No encontré coincidencias para "{term}". Podemos intentar con teléfono, correo o una parte del nombre.','No aparece ningún cliente relacionado con "{term}". Prueba con otro dato y afinamos la búsqueda.','No tuve coincidencias para "{term}". Podemos buscar por otro campo.'],
            'search_client_found' => ['Encontré {count} coincidencia(s) para "{term}" y te las dejo aquí.','Listo: tengo {count} resultado(s) relacionados con "{term}".','La búsqueda de "{term}" devolvió {count} resultado(s).'],
            'search_company_empty' => ['Dime el nombre, razón social o código de la empresa y la busco.','Necesito algún dato de la empresa para encontrarla.','Pásame el nombre comercial, razón social o código y hago la búsqueda.'],
            'search_company_none' => ['No encontré empresas relacionadas con "{term}".','No hay coincidencias visibles para "{term}". Podemos intentar con su código o razón social.','La búsqueda de "{term}" no devolvió empresas.'],
            'search_company_found' => ['Encontré {count} empresa(s) relacionadas con "{term}".','Listo: la búsqueda de "{term}" devolvió {count} resultado(s).','Tengo {count} coincidencia(s) para "{term}".'],
            'unknown' => ['Entiendo la intención, pero todavía no tengo una acción local para esa frase. Prueba a decirme qué quieres consultar, abrir o registrar.','Todavía no tengo ese comando mapeado. Puedes hablarme de clientes, empresas, solicitudes, proyectos, pagos o soporte.','Esa instrucción aún no está en mi repertorio local. Puedo consultar datos, buscar registros, navegar y preparar operaciones.'],
            'help' => ['Soy AGR, la capa inteligente local de VITI. Puedo consultar datos, buscar registros, navegar por módulos y preparar operaciones sin usar una API externa.','Aquí estoy. No solo consulto VITI: también puedo guiarte por módulos y preparar acciones seguras antes de guardar cambios.','Puedes hablarme de forma natural sobre VITI: datos, clientes, empresas, proyectos, solicitudes, pagos, soporte y operaciones protegidas.']
        ];

        $templates = $bank[$key] ?? $bank['unknown'];
        $index = $this->variantIndex($context, count($templates));
        $message = $templates[$index];

        if ($key === 'summary' && !empty($data)) {
            foreach ($data as $name => $value) $message = str_replace('{'.$name.'}', (string) $value, $message);
        }
        if ($count !== null) $message = str_replace('{count}', (string) $count, $message);
        foreach ($data as $name => $value) $message = str_replace('{'.$name.'}', (string) $value, $message);
        return $message;
    }

    private function variantIndex(string $context, int $total): int
    {
        if ($total <= 1) return 0;
        return hexdec(substr(hash('sha256', $context), 0, 8)) % $total;
    }

    private function cleanSearchTerm(string $term): string { return trim((string) preg_replace('/\s+/',' ',$term)); }
    private function matches(string $text, array $phrases): bool { foreach ($phrases as $phrase) if (str_contains($text,$this->normalize($phrase))) return true; return false; }
    private function normalize(string $text): string { return Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9\s]/',' ')->replaceMatches('/\s+/',' ')->trim()->toString(); }
}
