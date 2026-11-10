<?php

namespace App\Console\Commands;

use App\Support\AzureBlob;
use App\Support\Supabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class StorageMigrate extends Command
{
    protected $signature = 'tedc:storage-migrate {--from=local : local or supabase} {--dry-run : list what would be copied} {--buckets= : comma-separated bucket keys}';

    protected $description = 'Copy stored files to Azure Blob Storage with checksum verification; the source is never deleted (Phase 17)';

    public function handle(AzureBlob $azure): int
    {
        $from = (string) $this->option('from');
        if (! in_array($from, ['local', 'supabase'], true)) {
            $this->error('--from must be local or supabase');

            return self::FAILURE;
        }
        $buckets = array_unique(array_values(config('tedc.supabase.buckets')));
        if ($this->option('buckets')) {
            $buckets = array_values(array_intersect($buckets, array_map('trim', explode(',', (string) $this->option('buckets')))));
        }
        $copied = $skipped = $failed = 0;
        foreach ($buckets as $bucket) {
            foreach ($this->list($from, $bucket) as $path) {
                if ($this->option('dry-run')) {
                    $this->line("{$bucket}/{$path}");
                    $copied++;

                    continue;
                }
                try {
                    $bytes = $this->read($from, $bucket, $path);
                    $md5 = base64_encode(md5($bytes, true));
                    if ($azure->exists($bucket, $path) && $azure->md5($bucket, $path) === $md5) {
                        $skipped++;

                        continue;
                    }
                    $azure->put($bucket, $path, $bytes, 'application/octet-stream');
                    if ($azure->md5($bucket, $path) !== $md5) {
                        throw new \RuntimeException('checksum differs after upload');
                    }
                    $copied++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("{$bucket}/{$path}: {$e->getMessage()}");
                }
            }
        }
        $this->info(($this->option('dry-run') ? 'would copy ' : 'copied ')."{$copied}, already there {$skipped}, failed {$failed}");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return iterable<string> */
    private function list(string $from, string $bucket): iterable
    {
        if ($from === 'local') {
            foreach (Storage::disk('local')->allFiles($bucket) as $f) {
                yield substr($f, strlen($bucket) + 1);
            }

            return;
        }
        $offset = 0;
        do {
            $rows = Supabase::admin()->post(rtrim((string) config('tedc.supabase.url'), '/')."/storage/v1/object/list/{$bucket}", ['prefix' => '', 'limit' => 1000, 'offset' => $offset])->throw()->json() ?? [];
            foreach ($rows as $r) {
                if (! empty($r['name']) && isset($r['id'])) {
                    yield $r['name'];
                }
            }
            $offset += 1000;
        } while (count($rows) === 1000);
    }

    private function read(string $from, string $bucket, string $path): string
    {
        if ($from === 'local') {
            return (string) Storage::disk('local')->get("{$bucket}/{$path}");
        }

        return Supabase::admin()->get(rtrim((string) config('tedc.supabase.url'), '/')."/storage/v1/object/{$bucket}/{$path}")->throw()->body();
    }
}
