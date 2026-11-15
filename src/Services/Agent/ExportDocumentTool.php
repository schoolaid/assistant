<?php

namespace SchoolAid\Assistant\Services\Agent;

use SchoolAid\Assistant\Support\AssistantNaming;

/**
 * `export-document`: el modelo redacta un resumen (markdown) y el backend lo pasa a PDF con la plantilla del market.
 *
 * Es local del runner ({@see AssistantTurnRunner}), como {@see ExportFileTool}, y tampoco corta el turno. A diferencia de
 * `export-file`, el texto y sus cifras los escribe el modelo: por eso el pie del PDF dice que lo redactó el asistente y
 * que las cifras se confirman en los reportes del panel. Una lista de datos va con `export-file`, no aquí.
 *
 * El markdown se renderiza en el servidor con las mismas reglas que el chat: sin HTML ni imágenes, y los enlaces
 * quedan como texto ({@see \SchoolAid\Assistant\Services\Exports\AssistantExportPdf::documentHtml()}).
 */
final class ExportDocumentTool
{
    public const NAME = 'export-document';

    public const DESCRIPTION = 'Genera un PDF para descargar con un resumen que redactas tú (para una junta, para mandarlo por correo): título y el texto en markdown, con encabezados, párrafos, listas y tablas cortas. Úsalo solo cuando la persona pide un documento o un resumen en PDF; para una lista de datos usa export-file. Cada cifra tiene que salir de una tool que ya leíste, con su moneda; no inventes ni redondees. Sin imágenes, enlaces ni HTML. El PDF aparece en el chat con su botón para descargar; dile en una frase qué contiene, sin repetir el texto.';

    public const CREATED_RESULT = 'Preparando el PDF: aparece en el chat con su botón para descargar.';

    public const MAX_TITLE_CHARS = 120;

    public const MAX_MARKDOWN_CHARS = 20000;

    private const REJECTED_PREFIX = 'No generé el PDF: ';

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public static function definition(): array
    {
        return [
            'name' => self::NAME,
            'description' => AssistantNaming::toolDescription(self::NAME, self::DESCRIPTION),
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_TITLE_CHARS, 'description' => 'Qué es y de cuándo; es el nombre del archivo. P. ej. «Resumen de cobros — noviembre 2026».'],
                    'markdown' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_MARKDOWN_CHARS, 'description' => 'El cuerpo, en el idioma de la persona. Sin el título (ya va arriba).'],
                ],
                'required' => ['title', 'markdown'],
            ],
        ];
    }

    /**
     * El pedido normalizado, o el motivo del rechazo para el modelo.
     *
     * @param  array<string, mixed>  $input
     * @return array{title: string, markdown: string}|string
     */
    public static function validate(array $input): array|string
    {
        $title = $input['title'] ?? null;

        if (! is_string($title) || mb_strlen(trim($title)) < 1 || mb_strlen(trim($title)) > self::MAX_TITLE_CHARS) {
            return self::rejected('title tiene que ser texto de 1 a '.self::MAX_TITLE_CHARS.' caracteres.');
        }

        $markdown = $input['markdown'] ?? null;

        if (! is_string($markdown) || trim($markdown) === '') {
            return self::rejected('markdown tiene que traer el texto del documento.');
        }

        if (mb_strlen($markdown) > self::MAX_MARKDOWN_CHARS) {
            return self::rejected('el texto pasa de '.self::MAX_MARKDOWN_CHARS.' caracteres: resúmelo, o manda el detalle con export-file.');
        }

        return ['title' => trim($title), 'markdown' => trim($markdown)];
    }

    public static function rejected(string $reason): string
    {
        return self::REJECTED_PREFIX.$reason;
    }
}
