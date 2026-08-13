<?php

namespace App\Services;

use App\Models\SystemBackup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function create(string $source = 'manual', ?int $createdBy = null): SystemBackup
    {
        if (SystemBackup::query()->where('status', 'running')->where('started_at', '>=', now()->subMinutes(15))->exists()) {
            throw new RuntimeException('Ya existe un respaldo de base de datos en curso.');
        }

        $backup = SystemBackup::create([
            'status' => 'running',
            'source' => $source,
            'disk' => 'private_uploads',
            'created_by' => $createdBy,
            'started_at' => now(),
        ]);

        $tmpDir = storage_path('app/tmp/backups');
        File::ensureDirectoryExists($tmpDir);
        $tmpPath = $tmpDir.'/viti-'.$backup->id.'-'.now()->format('Ymd-His').'.dump';

        try {
            $connection = $this->postgresConnection();
            $this->runPgDump($connection, $tmpPath);

            if (!is_file($tmpPath) || filesize($tmpPath) <= 0) {
                throw new RuntimeException('pg_dump terminó sin producir un archivo de respaldo válido.');
            }

            $size = filesize($tmpPath);
            $checksum = hash_file('sha256', $tmpPath);
            if ($checksum === false) throw new RuntimeException('No se pudo calcular la huella SHA-256 del respaldo.');

            $remotePath = 'system-backups/'.now()->format('Y/m').'/viti-'.$backup->id.'-'.now()->format('Ymd-His').'.dump';
            $disk = Storage::disk('private_uploads');
            $stream = fopen($tmpPath, 'rb');
            if ($stream === false) throw new RuntimeException('No se pudo abrir el respaldo temporal para subirlo.');

            try {
                $written = $disk->put($remotePath, $stream);
            } finally {
                fclose($stream);
            }

            if (!$written || !$disk->exists($remotePath)) {
                throw new RuntimeException('El almacenamiento privado no confirmó la carga del respaldo.');
            }

            $remoteChecksum = $this->checksumRemote($remotePath);
            if (!hash_equals($checksum, $remoteChecksum)) {
                $disk->delete($remotePath);
                throw new RuntimeException('La verificación SHA-256 del respaldo remoto no coincide con el archivo original.');
            }

            $backup->update([
                'status' => 'verified',
                'path' => $remotePath,
                'checksum_sha256' => $checksum,
                'size_bytes' => $size,
                'completed_at' => now(),
                'verified_at' => now(),
                'failure_reason' => null,
            ]);

            return $backup->fresh();
        } catch (Throwable $e) {
            report($e);
            $backup->update([
                'status' => 'failed',
                'completed_at' => now(),
                'failure_reason' => $this->safeFailure($e->getMessage()),
            ]);
            throw $e;
        } finally {
            if (is_file($tmpPath)) @unlink($tmpPath);
        }
    }

    public function verify(SystemBackup $backup): SystemBackup
    {
        if (!$backup->path || !$backup->checksum_sha256) {
            throw new RuntimeException('Este respaldo no tiene ruta o checksum registrado para verificar.');
        }

        $diskName = $backup->disk ?: 'private_uploads';
        $disk = Storage::disk($diskName);
        if (!$disk->exists($backup->path)) {
            $backup->update(['status' => 'failed', 'failure_reason' => 'El archivo de respaldo ya no existe en el almacenamiento privado.']);
            throw new RuntimeException('El archivo de respaldo ya no existe en el almacenamiento privado.');
        }

        $remoteChecksum = $this->checksumRemote($backup->path, $diskName);
        if (!hash_equals($backup->checksum_sha256, $remoteChecksum)) {
            $backup->update(['status' => 'failed', 'failure_reason' => 'El checksum remoto ya no coincide con el registrado.']);
            throw new RuntimeException('El respaldo existe, pero su checksum ya no coincide con el registrado.');
        }

        $backup->update(['status' => 'verified', 'verified_at' => now(), 'failure_reason' => null]);
        return $backup->fresh();
    }

    private function runPgDump(array $connection, string $tmpPath): void
    {
        $process = new Process([
            'pg_dump', '--host', $connection['host'], '--port', (string) $connection['port'],
            '--username', $connection['username'], '--dbname', $connection['database'],
            '--format=custom', '--no-owner', '--no-privileges', '--file', $tmpPath,
        ], null, ['PGPASSWORD' => $connection['password'], 'PGSSLMODE' => $connection['sslmode']]);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('pg_dump no pudo completar el respaldo (código '.$process->getExitCode().').');
        }
    }

    private function postgresConnection(): array
    {
        if ((string) config('database.default') !== 'pgsql') {
            throw new RuntimeException('El respaldo lógico VITI está habilitado únicamente para PostgreSQL.');
        }

        $config = config('database.connections.pgsql', []);
        $url = (string) ($config['url'] ?? '');
        $parsed = $url !== '' ? parse_url($url) : false;
        $query = [];
        if (is_array($parsed) && isset($parsed['query'])) parse_str($parsed['query'], $query);

        $host = is_array($parsed) && isset($parsed['host']) ? $parsed['host'] : ($config['host'] ?? null);
        $port = is_array($parsed) && isset($parsed['port']) ? $parsed['port'] : ($config['port'] ?? 5432);
        $username = is_array($parsed) && isset($parsed['user']) ? rawurldecode($parsed['user']) : ($config['username'] ?? null);
        $password = is_array($parsed) && isset($parsed['pass']) ? rawurldecode($parsed['pass']) : ($config['password'] ?? '');
        $database = is_array($parsed) && isset($parsed['path']) ? ltrim(rawurldecode($parsed['path']), '/') : ($config['database'] ?? null);
        $sslmode = (string) ($query['sslmode'] ?? $config['sslmode'] ?? 'require');

        if (!$host || !$username || !$database) {
            throw new RuntimeException('La conexión PostgreSQL no tiene host, usuario o base de datos suficientes para crear el respaldo.');
        }

        return compact('host','port','username','password','database','sslmode');
    }

    private function checksumRemote(string $path, string $diskName = 'private_uploads'): string
    {
        $stream = Storage::disk($diskName)->readStream($path);
        if ($stream === false) throw new RuntimeException('No se pudo leer el respaldo remoto para verificar su integridad.');

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function safeFailure(string $message): string
    {
        $message = preg_replace('/postgres(?:ql)?:\/\/[^\s]+/i', '[conexion PostgreSQL oculta]', $message) ?? $message;
        $message = preg_replace('/(password|secret|token|credential)[=:][^\s]+/i', '$1=[oculto]', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        return mb_substr($message, 0, 500);
    }
}
