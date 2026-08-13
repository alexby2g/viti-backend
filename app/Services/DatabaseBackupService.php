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

            // Un checksum solo demuestra que los bytes no cambiaron. Antes de subir una copia,
            // PostgreSQL también debe poder interpretar el archivo como un archive restaurable.
            $this->assertArchiveReadable($tmpPath);

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

            // Guardamos primero la identidad esperada del archivo y verificamos de nuevo la copia
            // descargada desde el almacenamiento privado. La verificación final siempre recae sobre
            // el objeto remoto, no sobre el temporal que acabamos de crear.
            $backup->update([
                'path' => $remotePath,
                'checksum_sha256' => $checksum,
                'size_bytes' => $size,
            ]);

            $this->assertRemoteArchive($backup->fresh());

            $backup->update([
                'status' => 'verified',
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

        try {
            $this->assertRemoteArchive($backup);
            $backup->update(['status' => 'verified', 'verified_at' => now(), 'failure_reason' => null]);
            return $backup->fresh();
        } catch (Throwable $e) {
            $backup->update(['status' => 'failed', 'failure_reason' => $this->safeFailure($e->getMessage())]);
            throw $e;
        }
    }

    public function retentionPolicy(): array
    {
        return [
            'minimum_verified_copies' => 7,
            'freshness_hours' => 48,
            'suggested_retention_days' => 30,
            'automatic_pruning' => false,
            'message' => 'VITI conserva las copias verificadas. El borrado automático permanece desactivado hasta completar simulacros de restauración periódicos.',
        ];
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

    private function assertArchiveReadable(string $path): void
    {
        $process = new Process(['pg_restore', '--list', $path]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful() || trim($process->getOutput()) === '') {
            throw new RuntimeException('pg_restore no pudo interpretar el archivo de respaldo como un archive PostgreSQL restaurable.');
        }
    }

    private function assertRemoteArchive(SystemBackup $backup): void
    {
        $diskName = $backup->disk ?: 'private_uploads';
        $disk = Storage::disk($diskName);

        if (!$backup->path || !$disk->exists($backup->path)) {
            throw new RuntimeException('El archivo de respaldo ya no existe en el almacenamiento privado.');
        }

        $tmpDir = storage_path('app/tmp/backups/verify');
        File::ensureDirectoryExists($tmpDir);
        $tmpPath = $tmpDir.'/verify-'.$backup->id.'-'.bin2hex(random_bytes(6)).'.dump';
        $remote = $disk->readStream($backup->path);
        if ($remote === false) throw new RuntimeException('No se pudo leer el respaldo remoto para verificar su integridad.');

        $local = fopen($tmpPath, 'wb');
        if ($local === false) {
            fclose($remote);
            throw new RuntimeException('No se pudo crear el archivo temporal de verificación.');
        }

        try {
            if (stream_copy_to_stream($remote, $local) === false) {
                throw new RuntimeException('No se pudo completar la descarga temporal del respaldo remoto.');
            }
        } finally {
            fclose($remote);
            fclose($local);
        }

        try {
            $remoteChecksum = hash_file('sha256', $tmpPath);
            if ($remoteChecksum === false || !$backup->checksum_sha256 || !hash_equals($backup->checksum_sha256, $remoteChecksum)) {
                throw new RuntimeException('El checksum remoto ya no coincide con el registrado.');
            }

            if ($backup->size_bytes !== null && filesize($tmpPath) !== (int) $backup->size_bytes) {
                throw new RuntimeException('El tamaño remoto del respaldo ya no coincide con el registrado.');
            }

            $this->assertArchiveReadable($tmpPath);
        } finally {
            if (is_file($tmpPath)) @unlink($tmpPath);
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

    private function safeFailure(string $message): string
    {
        $message = preg_replace('/postgres(?:ql)?:\/\/[^\s]+/i', '[conexion PostgreSQL oculta]', $message) ?? $message;
        $message = preg_replace('/(password|secret|token|credential)[=:][^\s]+/i', '$1=[oculto]', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        return mb_substr($message, 0, 500);
    }
}
