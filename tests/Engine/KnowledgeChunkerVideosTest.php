<?php

use Illuminate\Support\Facades\Http;
use SchoolAid\Assistant\Knowledge\KnowledgeChunker;
use SchoolAid\Assistant\Knowledge\VideoGuideCatalog;
use SchoolAid\Assistant\Support\AssistantNaming;

/*
| ⚠️ THE CHUNKER MUST GET THE VIDEO CATALOG FROM THE CONTAINER. KnowledgeChunker takes it as an
| optional `?VideoGuideCatalog $videos = null`, and the container leaves an unbound optional class
| at its default: the chunker had no catalog, so with a catalog URL set it read no video guides,
| and `assistant:knowledge:index`, finding none, removed every `video-*` document from the index
| (the bug MarketAid #151 fixed in its app). The provider binds the catalog.
*/

it('hands the chunker the video catalog', function (): void {
    $videos = (fn () => $this->videos)->call(app(KnowledgeChunker::class));

    expect($videos)->toBeInstanceOf(VideoGuideCatalog::class);
});

it('turns the catalog\'s guides into video chunks when a catalog URL is set', function (): void {
    config()->set(AssistantNaming::name().'.knowledge.videos.catalog_url', 'https://guides.example.test/catalog.json');
    Http::fake(['guides.example.test/*' => Http::response([
        'version' => 1,
        'modules' => ['notes' => 'Notas'],
        'guides' => [['id' => 'send-a-note', 'module' => 'notes', 'title' => 'Enviar una nota', 'summary' => 'Cómo enviar una nota', 'steps' => []]],
    ])]);

    $chunks = app(KnowledgeChunker::class)->videoChunks();

    expect($chunks->map(fn ($chunk) => $chunk->id)->all())->toBe([KnowledgeChunker::VIDEO_ID_PREFIX.'send-a-note']);
});
