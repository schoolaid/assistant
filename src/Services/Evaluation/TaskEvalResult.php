<?php

namespace SchoolAid\Assistant\Services\Evaluation;

/**
 * Lo que midió el eval en una tarea del set ({@see TaskEvalRunner}).
 *
 * @phpstan-type Tokens array{input: int, output: int, cache_read: int, cache_write: int}
 * @phpstan-type Knowledge array{calls: int, withModule: int, correctModule: int|null}
 * @phpstan-type Guide array{answerable: bool, labels: list<array{page: string, section?: string}>, opened: list<string>, openedLabeled: bool, screens?: list<string>, linkedScreen?: bool|null, read: list<string>, verdict: array{verdict: string, invented: bool, comment: string}|null}
 */
final readonly class TaskEvalResult
{
    public const PASSED = 'PASS';

    public const FAILED = 'FAIL';

    public const SKIPPED = 'SKIP';

    public const ERRORED = 'ERROR';

    /**
     * @param  list<string>  $toolCalls  las tools que pidió el modelo, en orden (`offer-choices` incluida)
     * @param  list<string>  $previews  las escrituras que llegaron a vista previa, en orden
     * @param  list<string>  $writes  las escrituras confirmadas, en orden
     * @param  Tokens  $tokens
     * @param  Knowledge  $knowledge
     * @param  list<string>  $failures  por qué no pasó; vacío = pasó
     * @param  list<string>  $blockedHosts  llamadas HTTP que {@see EvalHttpGuard} no dejó salir
     * @param  array<string, int>  $jobs  Jobs que habrían salido a la cola, por clase
     * @param  list<string>  $unansweredQuestions  lo que el agente habría registrado con `report-unanswered-question`
     * @param  list<string>|null  $ungrounded  set de salida: cifras de la respuesta que no salieron de ninguna tool
     *                                          ({@see OutputChecks}); null en el set de tareas
     * @param  array{scores: array<string, int>, comment: string}|null  $judge  set de salida con `--judge` ({@see OutputJudge})
     * @param  Guide|null  $guide  set de conocimiento: las páginas etiquetadas, las que abrió, lo que leyó (búsqueda y páginas) y, con
     *                             `--judge`, el veredicto de {@see KnowledgeAnswerJudge}; null en los otros sets
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $request = '',
        public array $toolCalls = [],
        public int $toolSearches = 0,
        public array $previews = [],
        public array $writes = [],
        public bool $asked = false,
        public int $iterations = 0,
        public array $tokens = ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
        public float $costUsd = 0.0,
        public array $knowledge = ['calls' => 0, 'withModule' => 0, 'correctModule' => null],
        public string $finalText = '',
        public array $failures = [],
        public array $blockedHosts = [],
        public array $jobs = [],
        public array $unansweredQuestions = [],
        public ?array $ungrounded = null,
        public ?array $judge = null,
        public ?array $guide = null,
    ) {
    }

    public static function skipped(string $id, string $reason): self
    {
        return new self($id, self::SKIPPED, failures: [$reason]);
    }

    /**
     * @param  list<string>  $blockedHosts
     * @param  array<string, int>  $jobs
     * @param  list<string>  $unansweredQuestions
     */
    public function withSideEffects(array $blockedHosts, array $jobs, array $unansweredQuestions): self
    {
        return new self(
            $this->id, $this->status, $this->request, $this->toolCalls, $this->toolSearches, $this->previews, $this->writes,
            $this->asked, $this->iterations, $this->tokens, $this->costUsd, $this->knowledge, $this->finalText,
            $this->failures, $blockedHosts, $jobs, $unansweredQuestions, $this->ungrounded, $this->judge, $this->guide,
        );
    }

    /**
     * La nota del juez; su costo se suma al de la tarea.
     *
     * @param  array{scores: array<string, int>, comment: string}  $judge
     */
    public function withJudge(array $judge, float $costUsd): self
    {
        return new self(
            $this->id, $this->status, $this->request, $this->toolCalls, $this->toolSearches, $this->previews, $this->writes,
            $this->asked, $this->iterations, $this->tokens, $this->costUsd + $costUsd, $this->knowledge, $this->finalText,
            $this->failures, $this->blockedHosts, $this->jobs, $this->unansweredQuestions, $this->ungrounded, $judge, $this->guide,
        );
    }

    /**
     * El veredicto del juez del set de conocimiento; su costo se suma al de la pregunta. Con veredicto, pasa o falla
     * por él: no haber abierto una página etiquetada deja de ser falla (las etiquetas no son todas las páginas que
     * responden) y queda en `guide.openedLabeled`. Sin veredicto válido, el resultado no cambia.
     *
     * @param  array{verdict: string, invented: bool, comment: string}|null  $verdict
     */
    public function withGuideVerdict(?array $verdict, float $costUsd): self
    {
        $guide = [...(array) $this->guide, 'verdict' => $verdict];
        $failures = $this->failures;
        $status = $this->status;

        if ($verdict !== null) {
            $failures = [
                ...array_values(array_filter($failures, fn (string $failure): bool => ! str_starts_with($failure, KnowledgeAnswerChecks::NOT_OPENED))),
                ...KnowledgeAnswerChecks::verdictFailures((bool) ($guide['answerable'] ?? false), $verdict),
            ];
            $status = $status === self::ERRORED ? self::ERRORED : ($failures === [] ? self::PASSED : self::FAILED);
        }

        return new self(
            $this->id, $status, $this->request, $this->toolCalls, $this->toolSearches, $this->previews, $this->writes,
            $this->asked, $this->iterations, $this->tokens, $this->costUsd + $costUsd, $this->knowledge, $this->finalText,
            $failures, $this->blockedHosts, $this->jobs, $this->unansweredQuestions, $this->ungrounded, $this->judge, $guide,
        );
    }

    public function passed(): bool
    {
        return $this->status === self::PASSED;
    }
}
