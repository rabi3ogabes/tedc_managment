<?php

namespace Tests\Feature;

use App\Ai\AiPolicy;
use App\Ai\Assistant\TraineeAssistant;
use App\Ai\Rag\Embedders;
use App\Ai\Rag\Indexer;
use App\Ai\Rag\JsonCosineStore;
use App\Ai\Rag\LocalEmbedder;
use App\Ai\Rag\PgVectorStore;
use App\Ai\Rag\Retriever;
use App\Ai\Rag\Stores;
use App\Models\AiEvalRun;
use App\Models\AiIndexRun;
use App\Models\Embedding;
use App\Models\HelpArticle;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiModelSettings;
use Database\Seeders\HelpArticlesSeeder;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** AI-05: retrieval with a pluggable store and embedder, resumable re-embedding, a golden question set, and streamed answers. */
class AssistantRetrievalTest extends TestCase
{
    /** Texts sent to the fake embeddings model, across calls. */
    private static int $embedded = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(HelpArticlesSeeder::class);
        self::$embedded = 0;
        self::$failAfter = null;
    }

    private function trainee(): User
    {
        return $this->makeEmployee()->user;
    }

    private function article(string $slug): string
    {
        return HelpArticle::where('slug', $slug)->value('id');
    }

    /** An embeddings model on a Qatar connection. */
    private function embeddingsModel(): string
    {
        $settings = app(AiModelSettings::class);
        $c = $settings->saveConnection(['name' => 'Azure OpenAI Qatar', 'driver' => 'custom', 'base_url' => 'https://emb.test/v1', 'api_key' => 'sk-test', 'data_residency' => 'qatar']);
        $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'text-embedding-test', 'tasks' => ['embeddings']]);
        $settings->assign(['embeddings' => $m['id']]);
        app(AiPolicy::class)->save(['retrieval' => ['model' => ['min' => 0.05, 'strong' => 0.15]]]);   // the fake model's scores run lower than a real one's

        return $m['id'];
    }

    /** Calls the fake model answers before it starts failing (null: never), and calls so far. */
    private static ?int $failAfter = null;

    private static int $calls = 0;

    /**
     * The fake model turns each text into the local embedder's vector folded to 512 numbers. One stub serves the whole
     * test (Http::fake keeps the first stub for an address), steered by $failAfter.
     */
    private function fakeEmbeddings(): void
    {
        self::$calls = 0;
        Http::fake(['emb.test/*' => function ($request) {
            self::$calls++;
            if (self::$failAfter !== null && self::$calls > self::$failAfter) {
                return Http::response(['error' => ['message' => 'busy']], 503);
            }
            $inputs = array_values((array) $request->data()['input']);
            self::$embedded += count($inputs);
            $local = app(Embedders::class)->local();
            $fold = function (array $v) {
                $out = array_fill(0, 512, 0.0);
                foreach ($v as $i => $x) {
                    $out[$i % 512] += $x;
                }

                return $out;
            };

            return Http::response(['data' => array_map(fn ($t, $i) => ['index' => $i, 'embedding' => $fold($local->embed($t))], $inputs, array_keys($inputs))]);
        }]);
    }

    /** A text model on a Qatar connection that streams its answer. */
    private function streamingModel(string $sse): void
    {
        $settings = app(AiModelSettings::class);
        $c = $settings->saveConnection(['name' => 'Qatar', 'driver' => 'custom', 'base_url' => 'https://ai.test/v1', 'api_key' => 'sk-test', 'data_residency' => 'qatar']);
        $m = $settings->saveModel(['connection_id' => $c['id'], 'model' => 'gpt-test', 'tasks' => ['text']]);
        $settings->assign(['text' => $m['id']]);
        Http::fake(['ai.test/*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private static function sse(array $pieces): string
    {
        return implode('', array_map(fn ($p) => 'data: '.json_encode(['choices' => [['delta' => ['content' => $p]]]])."\n\n", $pieces))."data: [DONE]\n\n";
    }

    public function test_the_json_store_finds_the_help_article_that_answers_a_question(): void
    {
        app(Indexer::class)->all();

        $this->assertInstanceOf(DB::getDriverName() === 'pgsql' && app(PgVectorStore::class)->supported() ? PgVectorStore::class : JsonCosineStore::class, app(Stores::class)->active());
        $hits = app(Retriever::class)->search($this->trainee(), 'How can someone check that my certificate is real?', 3);
        $this->assertSame($this->article('certificates-and-verification'), $hits[0]['source_id']);
        $ar = app(Retriever::class)->search($this->trainee(), 'كيف أسجّل حضوري في الجلسة؟', 3);
        $this->assertSame($this->article('my-training-attendance'), $ar[0]['source_id']);
        $this->assertSame(LocalEmbedder::ID, $hits[0]['embedder']);
    }

    public function test_pgvector_gives_the_same_answers_as_the_json_store(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! app(PgVectorStore::class)->supported()) {
            $this->markTestSkipped('pgvector is not available on this database, so the JSON store is used. The PostgreSQL CI job runs this test on the pgvector image.');
        }
        app(Indexer::class)->all();
        $q = app(Embedders::class)->local()->embed('How do I turn on two-step verification?');
        $scope = fn ($w) => $w->where('visibility', 'public');

        $pg = app(PgVectorStore::class)->search($q, LocalEmbedder::ID, $scope, 3, 0.05);
        $json = app(JsonCosineStore::class)->search($q, LocalEmbedder::ID, $scope, 3, 0.05);

        $this->assertSame(array_map(fn ($h) => $h[0]->id, $json), array_map(fn ($h) => $h[0]->id, $pg));
        $this->assertEqualsWithDelta($json[0][1], $pg[0][1], 0.001);
    }

    public function test_every_row_records_its_model_and_dimension_and_models_are_never_mixed(): void
    {
        app(Indexer::class)->all();
        $model = $this->embeddingsModel();
        $this->fakeEmbeddings();

        $this->assertSame(0, Artisan::call('tedc:ai-reindex', ['--model' => $model, '--batch' => 10]));

        $this->assertSame([1024, 512], Embedding::distinct()->orderByDesc('dim')->pluck('dim')->map(fn ($d) => (int) $d)->all());
        $this->assertSame(Embedding::where('model', LocalEmbedder::ID)->count(), Embedding::where('model', "model:{$model}")->count());
        $this->assertSame("model:{$model}", app(Embedders::class)->active()->id());
        $hits = app(Retriever::class)->search($this->trainee(), 'How do I report a problem with the portal?', 3);
        $this->assertSame($this->article('report-a-problem'), $hits[0]['source_id']);
        $this->assertSame(["model:{$model}"], array_values(array_unique(array_column($hits, 'embedder'))));

        // The model cannot be reached: the answer comes from the local vectors, never from a mix.
        self::$failAfter = 0;
        self::$calls = 0;
        $fallback = app(Retriever::class)->search($this->trainee(), 'How do I report a problem with the portal?', 3);
        $this->assertSame([LocalEmbedder::ID], array_values(array_unique(array_column($fallback, 'embedder'))));
    }

    public function test_reindex_picks_up_where_it_stopped(): void
    {
        app(Indexer::class)->all();
        $chunks = Embedding::where('model', LocalEmbedder::ID)->count();
        $model = $this->embeddingsModel();
        self::$failAfter = 2;
        $this->fakeEmbeddings();

        $this->assertSame(1, Artisan::call('tedc:ai-reindex', ['--model' => $model, '--batch' => 5]));

        $run = AiIndexRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame(10, $run->done);
        $this->assertSame(LocalEmbedder::ID, app(Embedders::class)->active()->id());   // nothing switches half way

        self::$failAfter = null;   // the model is back
        $this->assertSame(0, Artisan::call('tedc:ai-reindex', ['--model' => $model, '--batch' => 5]));

        $this->assertSame($chunks, self::$embedded);   // no chunk was embedded twice
        $this->assertSame('done', AiIndexRun::whereKeyNot($run->id)->sole()->status);
        $this->assertSame("model:{$model}", app(Embedders::class)->active()->id());
    }

    public function test_the_golden_set_meets_the_thresholds_with_the_local_embedder(): void
    {
        $golden = json_decode((string) file_get_contents(resource_path('ai/golden-questions.json')), true);
        $this->assertCount(40, $golden['questions']);
        $this->assertSame(['ar', 'en'], collect($golden['questions'])->pluck('lang')->unique()->sort()->values()->all());
        app(Indexer::class)->all();

        $this->assertSame(0, Artisan::call('tedc:ai-eval'));

        $run = AiEvalRun::latest('created_at')->firstOrFail();
        $this->assertTrue($run->passed);
        $this->assertSame(40, $run->questions);
        $this->assertGreaterThanOrEqual(0.75, $run->hit_at_3);
        $this->assertGreaterThanOrEqual($run->hit_at_3, $run->hit_at_5);
        $this->assertGreaterThanOrEqual(0.75, $run->refusal_accuracy);
        $this->assertSame(LocalEmbedder::ID, $run->embedder);
    }

    public function test_the_answer_streams_in_pieces_then_the_message_arrives_with_its_sources(): void
    {
        app(Indexer::class)->all();
        $this->streamingModel(self::sse(['Open ', '"My certificates" ', 'to download it [1].']));
        $user = $this->trainee();

        $res = $this->asUser($user)->withHeaders(['Accept' => 'text/event-stream'])->post('/api/v1/me/assistant/messages', ['message' => 'How do I download my certificate as a PDF?']);

        $res->assertOk();
        $this->assertStringStartsWith('text/event-stream', $res->headers->get('Content-Type'));
        $body = $res->streamedContent();
        $this->assertStringContainsString('"delta":"Open "', $body);
        $this->assertStringContainsString('"delta":"to download it [1]."', $body);
        preg_match('/event: done\ndata: (.+)\n/', $body, $m);
        $done = json_decode($m[1], true);
        $this->assertSame('Open "My certificates" to download it [1].', $done['message']['content']);
        $this->assertSame('help', $done['message']['citations'][0]['type']);
        $this->assertSame('ai', $done['message']['meta']['source']);
        Http::assertSent(fn ($r) => ($r->data()['stream'] ?? false) === true);
    }

    public function test_stopping_keeps_what_was_said_so_far(): void
    {
        app(Indexer::class)->all();
        $this->streamingModel(self::sse(['Open ', '"My certificates" ', 'to download it [1].']));
        $user = $this->trainee();
        $assistant = app(TraineeAssistant::class);
        $pieces = [];

        $m = $assistant->ask($user, $assistant->conversation($user, null, 'en'), 'How do I download my certificate as a PDF?', function (string $d) use (&$pieces) {
            $pieces[] = $d;
        }, function () use (&$pieces) {
            return count($pieces) >= 1;
        });

        $this->assertSame(['Open '], $pieces);
        $this->assertSame('Open ', $m->content);
        $this->assertTrue($m->meta['cancelled']);
    }

    public function test_a_release_rebuilds_the_index_when_the_local_embedder_changed(): void
    {
        $article = HelpArticle::where('slug', 'getting-started')->firstOrFail();
        Embedding::where('model', LocalEmbedder::ID)->delete();
        Embedding::create(['source_type' => 'help', 'source_id' => $article->id, 'chunk_no' => 0, 'visibility' => 'public', 'lang' => 'en', 'title' => 'Old', 'route' => '/', 'content' => 'old',
            'vector' => array_fill(0, 256, 0.0), 'content_hash' => 'x', 'model' => 'local-hash-256', 'dim' => 256, 'updated_at' => now()]);

        $this->artisan('tedc:deploy', ['--no-cache' => true])->assertSuccessful();

        $this->assertSame(0, Embedding::where('model', 'local-hash-256')->count());
        $this->assertGreaterThan(0, Embedding::where('model', LocalEmbedder::ID)->where('source_id', $article->id)->count());
    }

    public function test_administrators_see_the_retrieval_status_reindex_and_evaluate(): void
    {
        $admin = $this->makeUser(Role::SUPER_ADMIN);
        $this->asUser($this->trainee())->getJson('/api/v1/admin/ai/retrieval')->assertForbidden();

        $status = $this->asUser($admin)->getJson('/api/v1/admin/ai/retrieval')->assertOk()->json('data');
        $this->assertSame(LocalEmbedder::ID, $status['embedder']['id']);
        $this->assertContains($status['store']['name'], ['json', 'pgvector']);

        $run = $this->asUser($admin)->postJson('/api/v1/admin/ai/reindex', ['model' => 'local'])->assertStatus(202)->json('data');
        $this->assertSame('done', AiIndexRun::findOrFail($run['id'])->status);
        $this->assertGreaterThan(0, $this->asUser($admin)->getJson('/api/v1/admin/ai/retrieval')->json('data.chunks'));

        $this->asUser($admin)->postJson('/api/v1/admin/ai/eval')->assertCreated()->assertJsonPath('data.questions', 40);
        $evals = $this->asUser($admin)->getJson('/api/v1/admin/ai/eval')->assertOk()->json('data');
        $this->assertCount(1, $evals['runs']);
        $this->assertSame(0.75, $evals['thresholds']['hit_at_3']);
    }
}
