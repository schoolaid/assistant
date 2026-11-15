<?php

namespace SchoolAid\Assistant\Services\Evaluation;

/**
 * Las preguntas, con su motivo entre corchetes, que el agente habría registrado con `report-unanswered-question`
 * durante el eval ({@see EvalUnansweredQuestionTool}): el comando las imprime por tarea en vez de dejarlas en la central.
 */
final class EvalUnansweredQuestions
{
    /**
     * @var list<string>
     */
    private array $questions = [];

    public function add(string $question): void
    {
        $this->questions[] = $question;
    }

    /**
     * Las preguntas desde la última vez que se leyeron.
     *
     * @return list<string>
     */
    public function pull(): array
    {
        $questions = $this->questions;
        $this->questions = [];

        return $questions;
    }
}
