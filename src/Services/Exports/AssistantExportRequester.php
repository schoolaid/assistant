<?php

namespace SchoolAid\Assistant\Services\Exports;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Enums\AssistantExportStatusEnum;
use SchoolAid\Assistant\Models\AssistantExport;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\ExportDocumentTool;
use SchoolAid\Assistant\Services\Agent\ExportFileTool;
use SchoolAid\Assistant\Services\Tools\AssistantToolCatalog;
use SchoolAid\Assistant\Services\Tools\ToolBridge;
use Illuminate\Support\Str;

/**
 * Lo que decide el runner cuando el modelo pide `export-file`, antes de encolar nada.
 *
 * 1. La forma del pedido ({@see ExportFileTool::validate()}).
 * 2. Que la fuente sea una **lectura** del catálogo. Una escritura, una tool negada o que no existe se rechaza; los
 *    permisos de la persona los revisa el puente al llamarla.
 * 3. El tope de archivos por hora de la persona (`assistant.exports.per_hour`), contando los de este mismo paso.
 * 4. La primera página por el puente: el argumento malo vuelve al modelo en el acto y se sabe el total. Se rechaza una
 *    forma no exportable ({@see AssistantExportRows::page()}), una lectura vacía, un total mayor que
 *    `assistant.exports.max_rows` del formato (en pdf se ofrece el xlsx), columnas que la lectura no trae y un pdf
 *    sin columns cuya lectura trae más de {@see ExportFileTool::PDF_MAX_COLUMNS} campos.
 *
 * `export-document` pasa por {@see self::requestDocument()}: solo la forma y el tope por hora.
 *
 * Si pasa, devuelve los atributos del {@see AssistantExport} `pending`. El runner lo crea con su mensaje `file` en el
 * cierre del paso, y {@see \SchoolAid\Assistant\Jobs\BuildAssistantExport} arma el archivo.
 */
class AssistantExportRequester
{
    public function __construct(
        private readonly ToolBridge $bridge,
        private readonly AssistantToolCatalog $catalog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  int  $requestedInStep  archivos ya pedidos en este mismo paso (todavía sin guardar)
     * @return array{result: string, isError: bool, export: array<string, mixed>|null}
     */
    public function request(AssistantTurn $turn, array $input, int $requestedInStep = 0): array
    {
        $request = ExportFileTool::validate($input);

        if (is_string($request)) {
            return self::rejected($request);
        }

        $tool = $this->catalog->find($request['source']['tool']);

        if ($tool === null || $this->catalog->groupOf($tool, $request['source']['arguments']) !== 0) {
            return self::rejected(ExportFileTool::rejected("{$request['source']['tool']} no es una lectura que pueda exportar."));
        }

        $userId = (int) $turn->conversation->user_id;

        if (($limit = $this->hourlyLimitReached($userId, $requestedInStep)) !== null) {
            return self::rejected(ExportFileTool::rejected($limit));
        }

        $pageSize = (int) AssistantNaming::config('exports.page_size');
        $first = $this->bridge->call(
            $request['source']['tool'],
            [...$request['source']['arguments'], 'page' => 1, 'perPage' => $pageSize],
            (string) $turn->run_id,
            fromModel: false,
        );

        if ($first->isError) {
            return self::rejected(ExportFileTool::rejected('la lectura falló: '.Str::limit($first->text, 500)));
        }

        $page = AssistantExportRows::page($first);

        if ($page === null) {
            return self::rejected(ExportFileTool::rejected("la respuesta de {$request['source']['tool']} no se puede exportar (no es una lista paginada, o es un reporte sin paginar con más de 50 filas: acótalo con filtros)."));
        }

        if ($page['total'] === 0) {
            return self::rejected(ExportFileTool::rejected('la lectura no trae filas. Dile a la persona que no hay datos con esos filtros.'));
        }

        $maxRows = (int) AssistantNaming::config("exports.max_rows.{$request['format']}");

        if ($page['total'] > $maxRows) {
            $xlsxRows = (int) AssistantNaming::config('exports.max_rows.xlsx');
            $alternative = $request['format'] === 'pdf' && $page['total'] <= $xlsxRows
                ? ' Ofrécele un xlsx (llega a '.$xlsxRows.' filas) o acota con filtros (fechas, sucursal, estado).'
                : ' Acota con filtros (fechas, sucursal, estado).';

            return self::rejected(ExportFileTool::rejected("son {$page['total']} filas y el tope de un {$request['format']} es {$maxRows}.{$alternative}"));
        }

        $available = AssistantExportRows::fields($page['rows']);

        if ($request['columns'] !== null) {
            $unknown = array_values(array_diff(array_column($request['columns'], 'field'), $available));

            if ($unknown !== []) {
                return self::rejected(ExportFileTool::rejected('esa lectura no trae '.implode(', ', $unknown).'. Campos disponibles: '.implode(', ', array_slice($available, 0, 60)).'.'));
            }
        } elseif ($request['format'] === 'pdf' && count($available) > ExportFileTool::PDF_MAX_COLUMNS) {
            return self::rejected(ExportFileTool::rejected('la lectura trae '.count($available).' campos y un pdf lleva hasta '.ExportFileTool::PDF_MAX_COLUMNS.' columnas: pasa columns con las que importan. Campos disponibles: '.implode(', ', array_slice($available, 0, 60)).'.'));
        }

        return [
            'result' => "Preparando «{$request['title']}» ({$page['total']} filas): aparece en el chat con su botón para descargar.",
            'isError' => false,
            'export' => [
                'id' => (string) Str::uuid(),
                'conversation_id' => $turn->conversation_id,
                'turn_id' => $turn->id,
                'user_id' => $userId,
                'format' => $request['format'],
                'title' => $request['title'],
                'source' => $request['source'],
                'columns' => $request['columns'],
                'rows' => $page['total'],
                'status' => AssistantExportStatusEnum::Pending,
            ],
        ];
    }

    /**
     * Lo que decide el runner cuando el modelo pide `export-document`: la forma ({@see ExportDocumentTool::validate()})
     * y el mismo tope por hora que `export-file`. El texto viaja en `source.markdown`; el PDF lo arma
     * {@see \SchoolAid\Assistant\Jobs\BuildAssistantExport}.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: string, isError: bool, export: array<string, mixed>|null}
     */
    public function requestDocument(AssistantTurn $turn, array $input, int $requestedInStep = 0): array
    {
        $document = ExportDocumentTool::validate($input);

        if (is_string($document)) {
            return self::rejected($document);
        }

        $userId = (int) $turn->conversation->user_id;

        if (($limit = $this->hourlyLimitReached($userId, $requestedInStep)) !== null) {
            return self::rejected(ExportDocumentTool::rejected($limit));
        }

        return [
            'result' => ExportDocumentTool::CREATED_RESULT,
            'isError' => false,
            'export' => [
                'id' => (string) Str::uuid(),
                'conversation_id' => $turn->conversation_id,
                'turn_id' => $turn->id,
                'user_id' => $userId,
                'format' => 'pdf',
                'title' => $document['title'],
                'source' => ['tool' => ExportDocumentTool::NAME, 'arguments' => [], 'markdown' => $document['markdown']],
                'columns' => null,
                'rows' => null,
                'status' => AssistantExportStatusEnum::Pending,
            ],
        ];
    }

    /**
     * El motivo para el modelo si la persona llegó al tope de `assistant.exports.per_hour`, contando los de este paso.
     */
    private function hourlyLimitReached(int $userId, int $requestedInStep): ?string
    {
        $perHour = (int) AssistantNaming::config('exports.per_hour');
        $lastHour = AssistantExport::query()->where('user_id', $userId)->where('created_at', '>=', now()->subHour())->count();

        return $lastHour + $requestedInStep >= $perHour
            ? "la persona llegó al tope de {$perHour} archivos por hora. Díselo y que lo pida más tarde."
            : null;
    }

    /**
     * @return array{result: string, isError: bool, export: null}
     */
    private static function rejected(string $reason): array
    {
        return ['result' => $reason, 'isError' => true, 'export' => null];
    }
}
