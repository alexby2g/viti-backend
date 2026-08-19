<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppHubController extends Controller
{
    /**
     * Lightweight AppHub API backed by the existing applications table.
     * It intentionally keeps application configuration isolated per record.
     */
    public function index(): JsonResponse
    {
        $apps = $this->tableExists('applications')
            ? DB::table('applications')->orderBy('name')->get()
            : collect();

        return response()->json(['data' => $apps]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tableExists('applications'), 404, 'Tabla applications no disponible.');
        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'icon' => ['nullable', 'string', 'max:1000'],
            'primary_color' => ['nullable', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'array'],
        ]);

        $existing = DB::table('applications')->where('id', $id)->first();
        abort_unless($existing, 404, 'Aplicación no encontrada.');
        if (array_key_exists('modules', $payload)) {
            $payload['modules'] = json_encode(array_values($payload['modules']));
        }
        DB::table('applications')->where('id', $id)->update($payload + ['updated_at' => now()]);
        return response()->json(['data' => DB::table('applications')->where('id', $id)->first()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->tableExists('applications'), 404, 'Tabla applications no disponible.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $id = (string) Str::uuid();
        $row = [
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $columns = DB::getSchemaBuilder()->getColumnListing('applications');
        $row = array_intersect_key($row, array_flip($columns));
        DB::table('applications')->insert($row);
        return response()->json(['data' => DB::table('applications')->where('id', $id)->first()], 201);
    }

    public function clone(Request $request, string $id): JsonResponse
    {
        abort_unless($this->tableExists('applications'), 404, 'Tabla applications no disponible.');
        $source = DB::table('applications')->where('id', $id)->first();
        abort_unless($source, 404, 'Aplicación no encontrada.');
        $columns = DB::getSchemaBuilder()->getColumnListing('applications');
        $row = (array) $source;
        $row['id'] = (string) Str::uuid();
        $row['name'] = $request->input('name', ($source->name ?? 'Aplicación') . ' — copia');
        if (in_array('created_at', $columns, true)) $row['created_at'] = now();
        if (in_array('updated_at', $columns, true)) $row['updated_at'] = now();
        $row = array_intersect_key($row, array_flip($columns));
        DB::table('applications')->insert($row);
        return response()->json(['data' => DB::table('applications')->where('id', $row['id'])->first()], 201);
    }

    public function users(string $id): JsonResponse
    {
        abort_unless($this->tableExists('users'), 404, 'Usuarios no disponibles.');
        $users = DB::table('users')->select('id', 'name', 'email')->orderBy('name')->get()->map(function ($u) {
            $u->role = 'Consulta';
            return $u;
        });
        return response()->json(['data' => $users]);
    }

    public function integrateUser(Request $request, string $id): JsonResponse
    {
        // This endpoint is intentionally a safe contract first. A dedicated pivot can be
        // introduced after the existing application/user relationship is confirmed.
        $data = $request->validate([
            'user_id' => ['required'],
            'role' => ['nullable', 'string', 'max:50'],
        ]);
        return response()->json(['message' => 'Usuario listo para integrar.', 'data' => $data], 201);
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
}
