<?php

namespace SchoolAid\Assistant\Services\Undo;

/**
 * La llamada que revierte una escritura del grupo 1: la tool y sus argumentos, sin token.
 */
final readonly class UndoCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $tool,
        public array $arguments,
    ) {
    }
}
