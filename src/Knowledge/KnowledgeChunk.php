<?php

namespace SchoolAid\Assistant\Knowledge;

/**
 * Un fragmento de conocimiento para el índice del asistente: una sección de una guía admin,
 * una entrada del banco de preguntas y respuestas o una guía en video del Manual del panel.
 *
 * `extra` son campos que solo llevan algunos fragmentos (los de video: `guide`, `menu`, `steps`,
 * `duration`). Van al documento solo cuando existen, así el hash de los demás no cambia y no se
 * reenvían al índice.
 */
final readonly class KnowledgeChunk
{
    public function __construct(
        public string $id,
        public string $source,
        public string $module,
        public string $moduleTitle,
        public string $page,
        public string $pageTitle,
        public string $heading,
        public ?string $anchor,
        public string $text,
        public string $hash,
        /** @var array<string, mixed> */
        public array $extra = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDocument(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'module' => $this->module,
            'moduleTitle' => $this->moduleTitle,
            'page' => $this->page,
            'pageTitle' => $this->pageTitle,
            'heading' => $this->heading,
            'anchor' => $this->anchor,
            'text' => $this->text,
            'hash' => $this->hash,
            ...$this->extra,
        ];
    }

    /**
     * Copia del fragmento con el hash calculado sobre todo el documento menos el propio hash,
     * de modo que cualquier cambio en un campo se vuelva a sincronizar.
     */
    public function sealed(): self
    {
        $document = $this->toDocument();
        unset($document['hash']);

        return new self(
            $this->id,
            $this->source,
            $this->module,
            $this->moduleTitle,
            $this->page,
            $this->pageTitle,
            $this->heading,
            $this->anchor,
            $this->text,
            sha1((string) json_encode($document, JSON_UNESCAPED_UNICODE)),
            $this->extra,
        );
    }
}
