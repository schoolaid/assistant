<?php

namespace SchoolAid\Assistant\Help;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Catálogo de las guías del administrador (`docs/modules/<módulo>/admin/*.md`) que el asistente
 * puede leer. Es la lista fija de lo que sale por las tools de ayuda: solo páginas con
 * frontmatter `audience: admin` que no estén `deprecated`, fuera de las carpetas internas que
 * empiezan con `_` (`_templates/admin/` son plantillas con el mismo frontmatter). Las páginas `dev/` documentan fallos
 * sin corregir y detalles internos, así que nunca entran, tampoco por un enlace: los enlaces que
 * apuntan fuera del catálogo se reducen a su texto.
 *
 * Una página se identifica por su ruta relativa a `docs/modules/` (`orders/admin/conceptos.md`).
 * Viaja en la imagen: `.dockerignore` deja pasar `docs/modules`.
 *
 * `menu` del frontmatter son las claves del menú del panel de las pantallas de las que habla la página (las mismas
 * de las guías en video): una página sin `menu` usa la del `admin/index.md` de su módulo, y `menu: []` la deja sin
 * pantalla. {@see PanelScreens} las vuelve enlaces, solo las que la persona ve.
 */
class HelpPages
{
    public const ROOT = 'docs/modules';

    private const SUMMARY_LENGTH = 240;

    private const FENCE = '/^\s*(```|~~~)/u';

    private const HEADING = '/^(#{1,6})\s+(.+?)\s*#*\s*$/u';

    private const LINK = '/\[([^\]]+)\]\(([^)\s]+)\)/u';

    private const FRONTMATTER = '/\A---\R(.*?)\R---\R/su';

    /**
     * @var Collection<string, array{page: string, module: string, title: string, order: int, status: string, updated: string, menu: list<string>}>|null
     */
    private ?Collection $catalog = null;

    /**
     * @param  string  $root  Raíz relativa a base_path(); los tests la apuntan a un fixture.
     */
    public function __construct(private readonly string $root = self::ROOT)
    {
    }

    /**
     * Un módulo por carpeta con al menos una página; título y resumen salen de su `admin/index.md`.
     * Sin índice, el título es el slug y el resumen sale de su primera página en orden de lectura.
     *
     * @return Collection<int, array{module: string, title: string, summary: string, pages: int}>
     */
    public function modules(): Collection
    {
        return $this->catalog()
            ->groupBy('module')
            ->map(function (Collection $pages, string $module): array {
                $index = $pages->firstWhere('page', "{$module}/admin/index.md");
                $first = $index ?? $pages->sortBy([['order', 'asc'], ['page', 'asc']])->first();

                return [
                    'module' => $module,
                    'title' => $index['title'] ?? $module,
                    'summary' => $this->summary($first['page']),
                    'pages' => $pages->count(),
                ];
            })
            ->sortBy('module')
            ->values();
    }

    /**
     * @return Collection<int, array{page: string, module: string, title: string, order: int, status: string, updated: string, menu: list<string>}>
     */
    public function pages(string $module): Collection
    {
        return $this->catalog()
            ->where('module', $module)
            ->sortBy([['order', 'asc'], ['page', 'asc']])
            ->values();
    }

    /**
     * @return array{page: string, module: string, title: string, order: int, status: string, updated: string, menu: list<string>}|null
     */
    public function find(string $page): ?array
    {
        return $this->catalog()->get($page);
    }

    /**
     * Las claves del menú del panel de las pantallas de la página (ver la clase); vacío si no es del catálogo.
     *
     * @return list<string>
     */
    public function menu(string $page): array
    {
        return $this->find($page)['menu'] ?? [];
    }

    /**
     * Encabezados de la página (fuera de bloques de código), hasta el nivel dado.
     *
     * @return array<int, string>
     */
    public function headings(string $page, int $maxLevel = 6): array
    {
        return collect($this->lines($page))
            ->filter(fn (array $line): bool => $line['level'] !== null && $line['level'] <= $maxLevel)
            ->pluck('heading')
            ->values()
            ->all();
    }

    /**
     * Cuerpo de la página sin frontmatter y con los enlaces reescritos; con `section`, solo desde
     * ese encabezado hasta el siguiente del mismo nivel o superior. La sección se busca por su
     * texto o por su ancla (`#que-se-puede-registrar`), sin distinguir acentos ni signos. Null si
     * la página o la sección no existen.
     */
    public function content(string $page, ?string $section = null): ?string
    {
        if ($this->find($page) === null) {
            return null;
        }

        $lines = $this->lines($page);

        if ($section !== null) {
            $wanted = Str::slug($section);
            $start = collect($lines)->search(fn (array $line): bool => $line['level'] !== null && Str::slug($line['heading']) === $wanted);

            if ($start === false) {
                return null;
            }

            $level = $lines[$start]['level'];
            $end = collect($lines)->slice($start + 1)
                ->search(fn (array $line): bool => $line['level'] !== null && $line['level'] <= $level);

            $lines = array_slice($lines, $start, $end === false ? null : $end - $start);
        }

        return trim($this->rewriteLinks(implode("\n", array_column($lines, 'text')), $page));
    }

    /**
     * @return Collection<string, array{page: string, module: string, title: string, order: int, status: string, updated: string, menu: list<string>}>
     */
    private function catalog(): Collection
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $catalog = collect(glob(base_path($this->root.'/*/admin/*.md')) ?: [])
            ->map(function (string $path): ?array {
                $page = Str::after($path, base_path($this->root).'/');

                if (str_starts_with($page, '_')) {
                    return null;
                }

                $meta = $this->frontmatter($page);

                if (($meta['audience'] ?? null) !== 'admin' || ($meta['status'] ?? null) === 'deprecated') {
                    return null;
                }

                return [
                    'page' => $page,
                    'module' => Str::before($page, '/'),
                    'title' => (string) ($meta['title'] ?? basename($page, '.md')),
                    'order' => (int) ($meta['order'] ?? 0),
                    'status' => (string) ($meta['status'] ?? 'stable'),
                    'updated' => (string) ($meta['updated'] ?? ''),
                    'menu' => self::menuKeys($meta['menu'] ?? null),
                    'ownMenu' => array_key_exists('menu', $meta),
                ];
            })
            ->filter()
            ->keyBy('page');

        return $this->catalog = $catalog->map(function (array $entry) use ($catalog): array {
            $index = $catalog->get("{$entry['module']}/admin/index.md");

            if (! $entry['ownMenu'] && $index !== null) {
                $entry['menu'] = $index['menu'];
            }

            unset($entry['ownMenu']);

            return $entry;
        });
    }

    /**
     * `menu` del frontmatter como lista de claves; cualquier otra forma cuenta como sin pantallas.
     *
     * @return list<string>
     */
    private static function menuKeys(mixed $menu): array
    {
        if (! is_array($menu)) {
            return [];
        }

        return array_values(array_unique(array_filter($menu, fn (mixed $key): bool => is_string($key) && $key !== '')));
    }

    /**
     * Frontmatter YAML de la página; vacío si no tiene o no se puede leer (la página queda
     * fuera del catálogo: falla cerrado).
     *
     * @return array<string, mixed>
     */
    private function frontmatter(string $page): array
    {
        if (! preg_match(self::FRONTMATTER, $this->raw($page), $match)) {
            return [];
        }

        try {
            $meta = Yaml::parse($match[1], Yaml::PARSE_DATETIME);
        } catch (ParseException) {
            return [];
        }

        if (! is_array($meta)) {
            return [];
        }

        return array_map(fn (mixed $value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $meta);
    }

    /**
     * Líneas del cuerpo (sin frontmatter) con su nivel de encabezado, o null si no lo es o
     * está dentro de un bloque de código.
     *
     * @return array<int, array{text: string, level: int|null, heading: string}>
     */
    private function lines(string $page): array
    {
        $body = preg_replace(self::FRONTMATTER, '', $this->raw($page));
        $inFence = false;
        $lines = [];

        foreach (preg_split('/\R/u', (string) $body) ?: [] as $text) {
            if (preg_match(self::FENCE, $text)) {
                $inFence = ! $inFence;
            }

            $isHeading = ! $inFence && preg_match(self::HEADING, $text, $heading);

            $lines[] = [
                'text' => $text,
                'level' => $isHeading ? strlen($heading[1]) : null,
                'heading' => $isHeading ? $heading[2] : '',
            ];
        }

        return $lines;
    }

    /**
     * Primer párrafo de texto bajo el H1 (saltando avisos en cita `>`), en una línea. Si el H1 va
     * seguido de otro encabezado, el primer párrafo que haya después.
     */
    private function summary(string $page): string
    {
        $paragraph = collect($this->lines($page))
            ->skipUntil(fn (array $line): bool => $line['level'] === 1)
            ->skip(1)
            ->skipUntil(fn (array $line): bool => trim($line['text']) !== '' && $line['level'] === null && ! str_starts_with(ltrim($line['text']), '>'))
            ->takeUntil(fn (array $line): bool => trim($line['text']) === '' || $line['level'] !== null)
            ->pluck('text')
            ->implode(' ');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $this->rewriteLinks($paragraph, $page, plain: true))), self::SUMMARY_LENGTH);
    }

    /**
     * Un enlace a otra página del catálogo queda como su id (`payment/admin/tarjetas.md#ancla`),
     * que get-help-page acepta tal cual; los externos (http) se conservan y el resto (dev/,
     * carpetas, archivos internos) se reduce a su texto. Con `plain`, todos quedan como texto.
     */
    private function rewriteLinks(string $markdown, string $page, bool $plain = false): string
    {
        return (string) preg_replace_callback(self::LINK, function (array $link) use ($page, $plain): string {
            [, $text, $target] = $link;

            if ($plain) {
                return $text;
            }

            if (preg_match('#^https?://#', $target)) {
                return $link[0];
            }

            [$path, $anchor] = array_pad(explode('#', $target, 2), 2, null);
            $resolved = $path === '' ? $page : $this->resolve(dirname($page), $path);
            $suffix = $anchor !== null && $anchor !== '' ? "#{$anchor}" : '';

            return $resolved !== null && $this->find($resolved) !== null ? "[{$text}]({$resolved}{$suffix})" : $text;
        }, $markdown);
    }

    /**
     * Ruta relativa a `docs/modules/` de un enlace relativo, o null si sale de esa raíz.
     */
    private function resolve(string $directory, string $path): ?string
    {
        $parts = [];

        foreach (explode('/', "{$directory}/{$path}") as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($parts === []) {
                    return null;
                }

                array_pop($parts);

                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function raw(string $page): string
    {
        return (string) file_get_contents(base_path("{$this->root}/{$page}"));
    }
}
