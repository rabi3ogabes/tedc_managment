<?php

namespace App\Console\Commands;

use App\Ai\Rag\Embedders;
use App\Ai\Rag\EmbedderUnavailable;
use App\Ai\Rag\Reindexer;
use App\Models\AiIndexRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tedc:ai-reindex {--model= : "local" or the id of an embeddings model; empty fills the embedder in use} {--batch=50 : Pieces per call to the model}')]
#[Description("Embed the assistant's retrieval index again. It can be stopped and run again: what is already embedded is skipped")]
class AiReindex extends Command
{
    public function handle(Reindexer $reindexer, Embedders $embedders): int
    {
        $target = (string) ($this->option('model') ?: $embedders->active()->id());
        try {
            $run = $reindexer->start($target);
        } catch (EmbedderUnavailable) {
            $this->error("No embeddings model \"{$target}\". Add it in Settings → AI models with the work \"embeddings\".");

            return self::FAILURE;
        }
        $bar = null;
        $run = $reindexer->run($run, null, (int) $this->option('batch'), function (AiIndexRun $r) use (&$bar) {
            $bar ??= $this->output->createProgressBar(max(1, $r->total));
            $bar->setProgress($r->done);
        });
        $bar?->finish();
        $this->newLine();
        if ($run->status !== 'done') {
            $this->error("Stopped at {$run->done} of {$run->total}: {$run->error} Run the command again to continue.");

            return self::FAILURE;
        }
        $this->info("Done: {$run->total} pieces with {$run->model}, now in use.");

        return self::SUCCESS;
    }
}
