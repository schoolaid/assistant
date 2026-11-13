<?php

namespace SchoolAid\Assistant\Console;

use SchoolAid\Assistant\Knowledge\KnowledgeChunker;
use SchoolAid\Assistant\Knowledge\MeilisearchKnowledgeIndex;
use Illuminate\Console\Command;
use Illuminate\Http\Client\HttpClientException;
use RuntimeException;

/**
 * Indexa en Meilisearch la guía admin, el banco de preguntas del asistente y las guías en video
 * del Manual del panel (catálogo público del front). Si ese catálogo no se puede leer, avisa e
 * indexa lo demás sin tocar las guías en video que ya estaban: lo publica otro repo, a su ritmo.
 * Es central: el índice es global y no inicializa tenancy.
 */
class IndexKnowledgeCommand extends Command
{
    protected $signature = 'assistant:knowledge:index {--dry-run : Solo cuenta los fragmentos, sin tocar el índice}';

    protected $description = 'Indexa la base de conocimiento del asistente del panel en Meilisearch';

    public function handle(KnowledgeChunker $chunker, MeilisearchKnowledgeIndex $index): int
    {
        $chunks = $chunker->pageChunks();
        $videosError = null;

        try {
            $videos = $chunker->videoChunks();
        } catch (RuntimeException|HttpClientException $e) {
            $videos = collect();
            $videosError = $e->getMessage();
        }

        $chunks = $chunks->concat($videos)->values();
        $summary = sprintf(
            'Fragmentos: %d (guía %d, FAQ %d, video %s)',
            $chunks->count(),
            $chunks->where('source', 'guide')->count(),
            $chunks->where('source', 'faq')->count(),
            $videosError === null ? $videos->count() : 'sin leer',
        );

        if ($videosError !== null) {
            $this->warn("No se pudieron leer las guías en video: {$videosError}. La guía y el FAQ se indexan igual; las guías en video que ya estén en el índice quedan como están.");
        }

        if ($this->option('dry-run')) {
            $this->line($summary);

            return self::SUCCESS;
        }

        try {
            $index->configure();
            $result = $index->sync($chunks, $videosError === null ? null : KnowledgeChunker::VIDEO_ID_PREFIX);
            $coverage = $index->vectorCoverage();
        } catch (RuntimeException|HttpClientException $e) {
            $this->error('No se pudo indexar: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            '%s · nuevos %d · cambiados %d · borrados %d · sin cambios %d',
            $summary,
            $result['added'],
            $result['updated'],
            $result['deleted'],
            $result['unchanged'],
        ));

        return $this->reportVectorCoverage($coverage);
    }

    /**
     * Falla si algún documento quedó sin vector: la búsqueda semántica no lo encuentra y el eval
     * sale bajo. Volver a correr el comando no lo arregla (el texto no cambió y no se reenvía).
     *
     * @param  array{documents: int, without_vectors: ?int}  $coverage
     */
    private function reportVectorCoverage(array $coverage): int
    {
        if ($coverage['without_vectors'] === null) {
            $this->warn('No se pudo comprobar que cada fragmento tenga su vector: esta versión de Meilisearch no informa numberOfEmbeddedDocuments.');

            return self::SUCCESS;
        }

        if ($coverage['without_vectors'] > 0) {
            $this->error(sprintf(
                '%d de %d fragmentos quedaron sin vector. No enciendas search-knowledge: borra el índice y vuelve a indexar en una versión de Meilisearch que embeba todo (ver docs/modules/assistant/dev/conocimiento.md).',
                $coverage['without_vectors'],
                $coverage['documents'],
            ));

            return self::FAILURE;
        }

        $this->line("Vectores: {$coverage['documents']} de {$coverage['documents']}");

        return self::SUCCESS;
    }
}
