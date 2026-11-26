<?php

use SchoolAid\Assistant\Knowledge\KnowledgeSearch;
use SchoolAid\Assistant\Knowledge\MeilisearchKnowledgeIndex;
use SchoolAid\Assistant\Knowledge\VideoGuideCatalog;
use SchoolAid\Assistant\Services\Audience\AudienceContracts;
use SchoolAid\Assistant\Support\AssistantActionEventMark;

/**
 * @return array<string, array{0: string}>  relative path => class name
 */
function packageClasses(): array
{
    $roots = [
        dirname(__DIR__, 2).'/src' => 'SchoolAid\\Assistant',
        dirname(__DIR__, 2).'/database/factories' => 'SchoolAid\\Assistant\\Database\\Factories',
    ];
    $classes = [];

    foreach ($roots as $root => $namespace) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $classes[basename($root).'/'.$relative] = [$namespace.'\\'.str_replace('/', '\\', substr($relative, 0, -4))];
        }
    }

    ksort($classes);

    return $classes;
}

it('loads outside MarketAid', function (string $class) {
    expect(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class))->toBeTrue();
})->with(packageClasses());

it('binds what the engine provides by itself', function () {
    expect(app(KnowledgeSearch::class))->toBeInstanceOf(MeilisearchKnowledgeIndex::class)
        ->and(app(AssistantActionEventMark::class))->toBe(app(AssistantActionEventMark::class))
        ->and(app(AudienceContracts::class))->toBe(app(AudienceContracts::class))
        ->and(app(VideoGuideCatalog::class))->toBe(app(VideoGuideCatalog::class));
});

it('references no MarketAid class', function () {
    foreach (packageClasses() as $relative => [$class]) {
        $root = str_starts_with($relative, 'src/') ? dirname(__DIR__, 2).'/' : dirname(__DIR__, 2).'/database/';
        $code = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents($root.$relative));

        expect(preg_match('/(^use |\\\\)(App|Said|Stancl)\\\\/m', $code))->toBe(0, $relative);
    }
});
