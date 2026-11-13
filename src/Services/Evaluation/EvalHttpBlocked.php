<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use RuntimeException;

/**
 * Una llamada HTTP que el eval no deja salir: solo pasan la API de Anthropic y el índice de conocimiento
 * ({@see EvalHttpGuard}).
 */
class EvalHttpBlocked extends RuntimeException
{
    /**
     * @param  list<string>  $allowedHosts
     */
    public function __construct(public readonly string $host, array $allowedHosts)
    {
        parent::__construct("assistant:eval bloqueó una llamada a {$host}: durante el eval solo salen ".implode(', ', $allowedHosts).'.');
    }
}
