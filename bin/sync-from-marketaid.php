#!/usr/bin/env php
<?php

/**
 * Copies the assistant engine out of a MarketAid checkout into `src/`, rewriting its namespace.
 *
 * While the engine still lives in MarketAid (`app/Modules/Assistant`), MarketAid is the source of
 * truth and this package is generated from it: run this again after every change there. What is
 * MarketAid's own (the `Host/` folder and the files listed in ASSISTANT_HOST_FILES of
 * `tests/Unit/Assistant/AssistantBoundaryTest.php`) is left behind.
 *
 * Usage: php bin/sync-from-marketaid.php /path/to/marketaid [--check]
 *
 * It ends with a list of blockers: references the copied code still makes to classes that are not
 * in this package. The package is usable only when that list is empty. With `--check` nothing is
 * written and the exit code is 1 while blockers remain.
 */
const SOURCE_NAMESPACE = 'App\\Modules\\Assistant';
const TARGET_NAMESPACE = 'SchoolAid\\Assistant';

/**
 * Engine files that the package replaces with its own version, kept in `overrides/`.
 */
const REPLACED_BY_PACKAGE = ['Providers/AssistantServiceProvider.php'];

/**
 * Classes the engine uses that live outside the module in MarketAid and move into the package
 * with it: MarketAid path (a glob) => [target directory in the package, source namespace, target
 * namespace]. They have no dependency on the app.
 */
const SHARED_SOURCES = [
    'app/Mcp/Support/HelpPages.php' => ['src/Help', 'App\\Mcp\\Support', 'SchoolAid\\Assistant\\Help'],
    'app/Mcp/Support/HelpBank.php' => ['src/Help', 'App\\Mcp\\Support', 'SchoolAid\\Assistant\\Help'],
    'database/factories/Assistant*Factory.php' => ['database/factories', 'Database\\Factories', 'SchoolAid\\Assistant\\Database\\Factories'],
];

/**
 * Files copied as they are: MarketAid path (a glob) => directory in the package. The translations
 * also carry MarketAid's own texts (its tools, its suggestions); an app publishes them and replaces
 * those sections with its own.
 */
const COPIED_AS_IS = [
    'resources/lang/es/assistant.php' => 'resources/lang/es',
    'resources/lang/en/assistant.php' => 'resources/lang/en',
];

$arguments = array_values(array_filter(array_slice($argv, 1), fn (string $argument): bool => ! str_starts_with($argument, '--')));
$checkOnly = in_array('--check', $argv, true);
$marketaid = rtrim($arguments[0] ?? '', '/');
$module = $marketaid.'/app/Modules/Assistant';
$boundaryTest = $marketaid.'/tests/Unit/Assistant/AssistantBoundaryTest.php';

if ($marketaid === '' || ! is_dir($module) || ! is_file($boundaryTest)) {
    fwrite(STDERR, "Usage: php bin/sync-from-marketaid.php /path/to/marketaid [--check]\n");
    exit(2);
}

$hostFiles = hostFiles((string) file_get_contents($boundaryTest));
$target = dirname(__DIR__).'/src';
$sources = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($module) + 1));
    $sources[$relative] = (string) file_get_contents($file->getPathname());
}

ksort($sources);

$shared = sharedSources($marketaid);

$isHost = fn (string $relative): bool => str_starts_with($relative, 'Host/') || in_array($relative, $hostFiles, true);
$engine = array_filter($sources, fn (string $relative): bool => ! $isHost($relative) && ! in_array($relative, REPLACED_BY_PACKAGE, true), ARRAY_FILTER_USE_KEY);
$leftBehind = array_filter(array_keys($sources), $isHost);
$blockers = blockers($engine, $leftBehind, array_keys($shared['classes']));

if (! $checkOnly) {
    clearDirectory($target);

    foreach ($engine as $relative => $contents) {
        $path = $target.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, rewrite($contents, $shared['classes']));
    }

    copyOverrides(dirname(__DIR__).'/overrides', $target);

    foreach (array_unique(array_map('dirname', array_keys($shared['files']))) as $directory) {
        if ($directory !== $target.'/Help') {
            clearDirectory($directory);
        }
    }

    foreach ($shared['files'] as $path => $contents) {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, rewrite($contents, $shared['classes']));
    }
}

if (! $checkOnly) {
    foreach (COPIED_AS_IS as $pattern => $directory) {
        foreach (glob($marketaid.'/'.$pattern) ?: [] as $path) {
            $destination = dirname(__DIR__).'/'.$directory;

            if (! is_dir($destination)) {
                mkdir($destination, 0755, true);
            }

            copy($path, $destination.'/'.basename($path));
        }
    }
}

printf("%s %d engine files and %d shared files (%d left in MarketAid).\n", $checkOnly ? 'Checked' : 'Copied', count($engine), count($shared['files']), count($leftBehind));

if ($blockers === []) {
    echo "No blockers: the engine references nothing outside this package.\n";
    exit(0);
}

printf("\n%d files with blockers:\n", count($blockers));

foreach ($blockers as $relative => $references) {
    echo "  {$relative}\n";

    foreach ($references as $reference) {
        echo "    - {$reference}\n";
    }
}

exit($checkOnly ? 1 : 0);

/**
 * @return array{files: array<string, string>, classes: array<string, string>}  target path => contents, and source class => target class
 */
function sharedSources(string $marketaid): array
{
    $files = [];
    $classes = [];

    foreach (SHARED_SOURCES as $pattern => [$directory, $sourceNamespace, $targetNamespace]) {
        foreach (glob($marketaid.'/'.$pattern) ?: [] as $path) {
            $class = basename($path, '.php');
            $files[dirname(__DIR__).'/'.$directory.'/'.basename($path)] = str_replace(
                "namespace {$sourceNamespace};",
                "namespace {$targetNamespace};",
                (string) file_get_contents($path),
            );
            $classes[$sourceNamespace.'\\'.$class] = $targetNamespace.'\\'.$class;
        }
    }

    return ['files' => $files, 'classes' => $classes];
}

/**
 * @param  array<string, string>  $sharedClasses  source class => target class
 */
function rewrite(string $contents, array $sharedClasses): string
{
    return str_replace(SOURCE_NAMESPACE, TARGET_NAMESPACE, strtr($contents, $sharedClasses));
}

/**
 * @return list<string>
 */
function hostFiles(string $boundaryTest): array
{
    if (preg_match('/const ASSISTANT_HOST_FILES = \[(.*?)\];/s', $boundaryTest, $match) !== 1) {
        fwrite(STDERR, "ASSISTANT_HOST_FILES not found in the boundary test.\n");
        exit(2);
    }

    preg_match_all("/'([^']+\\.php)'/", $match[1], $files);

    return $files[1];
}

/**
 * What each engine file references that will not exist in the package: app classes it imports and
 * classes of the module that stay in MarketAid.
 *
 * @param  array<string, string>  $engine  relative path => contents
 * @param  list<string>  $leftBehind  relative paths that stay in MarketAid
 * @param  list<string>  $sharedClasses  app classes that move into the package with the engine
 * @return array<string, list<string>>
 */
function blockers(array $engine, array $leftBehind, array $sharedClasses): array
{
    $hostClasses = array_map(fn (string $relative): string => SOURCE_NAMESPACE.'\\'.str_replace('/', '\\', substr($relative, 0, -4)), $leftBehind);
    $blockers = [];

    foreach ($engine as $relative => $contents) {
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $contents);
        $found = [];

        preg_match_all('/^use ((?:App|Said|Stancl|SchoolAid|Database)\\\\[\w\\\\]+)/m', $code, $imports);

        foreach ($imports[1] as $import) {
            if (! str_starts_with($import, SOURCE_NAMESPACE.'\\') && ! in_array($import, $sharedClasses, true)) {
                $found[] = "app class: {$import}";
            }
        }

        preg_match_all('/(?<![\w\\\\])\\\\((?:App|Said|Stancl)\\\\[\w\\\\]+)/', $code, $qualified);

        foreach ($qualified[1] as $class) {
            if (! str_starts_with($class, SOURCE_NAMESPACE.'\\') && ! in_array($class, $sharedClasses, true)) {
                $found[] = "app class: {$class}";
            }
        }

        $ownNamespace = SOURCE_NAMESPACE.(str_contains($relative, '/') ? '\\'.str_replace('/', '\\', dirname($relative)) : '');

        foreach ($hostClasses as $hostClass) {
            $short = substr($hostClass, strrpos($hostClass, '\\') + 1);
            $sameNamespace = substr($hostClass, 0, strrpos($hostClass, '\\')) === $ownNamespace;

            $imported = preg_match('/^use '.preg_quote($hostClass, '/').';/m', $code) === 1;
            $qualified = preg_match('/'.preg_quote('\\'.$hostClass, '/').'\b/', $code) === 1;
            $bare = $sameNamespace && preg_match('/(?<![\\\\\w$>:])'.preg_quote($short, '/').'\b/', $code) === 1;

            if ($imported || $qualified || $bare) {
                $found[] = "stays in MarketAid: {$short}";
            }
        }

        if ($found !== []) {
            sort($found);
            $blockers[$relative] = array_values(array_unique($found));
        }
    }

    return $blockers;
}

/**
 * The package's own version of the files in REPLACED_BY_PACKAGE, kept in `overrides/` so a sync
 * does not wipe them.
 */
function copyOverrides(string $overrides, string $target): void
{
    if (! is_dir($overrides)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($overrides, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $target.substr($file->getPathname(), strlen($overrides));

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        copy($file->getPathname(), $path);
    }
}

function clearDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);

        return;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
}
