<?php

use SchoolAid\Assistant\Services\Agent\ExportDocumentTool;
use SchoolAid\Assistant\Services\Agent\ExportFileTool;
use SchoolAid\Assistant\Services\Agent\OfferChoicesTool;
use SchoolAid\Assistant\Services\Agent\ShowChartTool;
use SchoolAid\Assistant\Services\Exports\AssistantExportSheet;
use SchoolAid\Assistant\Support\AssistantNaming;

/*
| What the engine says in MarketAid's words, an app replaces from its config: how the model reads
| the engine's own tools, and who signs the files it builds.
*/

afterEach(fn () => AssistantNaming::use(AssistantNaming::DEFAULT));

it('describes its own tools as the engine does when the app says nothing', function (string $tool) {
    expect($tool::definition()['description'])->toBe($tool::DESCRIPTION);
})->with([OfferChoicesTool::class, ShowChartTool::class, ExportFileTool::class, ExportDocumentTool::class]);

it('describes a tool with the words of the app, under the name the app registered', function (string $tool) {
    AssistantNaming::use('panel_assistant');
    config(['panel_assistant.tool_descriptions' => [$tool::NAME => 'Ask with buttons: the grade, the route, the user.']]);

    expect($tool::definition()['description'])->toBe('Ask with buttons: the grade, the route, the user.')
        ->and($tool::definition()['name'])->toBe($tool::NAME);
})->with([OfferChoicesTool::class, ShowChartTool::class, ExportFileTool::class, ExportDocumentTool::class]);

it('keeps its own description for a tool the app left out or left empty', function (mixed $own) {
    config(['assistant.tool_descriptions' => ['export-file' => 'Another one.', 'offer-choices' => $own]]);

    expect(OfferChoicesTool::definition()['description'])->toBe(OfferChoicesTool::DESCRIPTION)
        ->and(ShowChartTool::definition()['description'])->toBe(ShowChartTool::DESCRIPTION)
        ->and(ExportFileTool::definition()['description'])->toBe('Another one.');
})->with(['empty' => [''], 'blank' => ['  '], 'not a text' => [['x']], 'null' => [null]]);

it('signs the files as the app says, and as the engine when it says nothing', function () {
    $sheet = new AssistantExportSheet('Buses', [], null);

    expect($sheet->properties())->toBe(['title' => 'Buses', 'creator' => AssistantNaming::DEFAULT_EXPORT_CREATOR]);

    config(['assistant.exports.creator' => 'SchoolAid · asistente']);

    expect($sheet->properties())->toBe(['title' => 'Buses', 'creator' => 'SchoolAid · asistente'])
        ->and(AssistantNaming::exportCreator())->toBe('SchoolAid · asistente');
});
