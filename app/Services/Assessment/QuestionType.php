<?php

namespace App\Services\Assessment;

/** A kind of question. New kinds are added by implementing this and registering them in {@see QuestionTypes}. */
interface QuestionType
{
    public function key(): string;

    /** Checks and normalises the payload an author sent; throws a ValidationException (field `payload`) when it is not usable. */
    public function validate(array $payload): array;

    /**
     * @return array{ratio: float, manual: bool} the share of the points earned (0–1); `manual` means a person must grade it
     */
    public function grade(array $payload, mixed $answer): array;

    /** What the trainee may see while answering: no answers, and shuffled when asked to. */
    public function publicPayload(array $payload, bool $shuffle): array;

    /** The correct answer in the shape the trainee would send it, for "show correct answers". */
    public function correctAnswer(array $payload): mixed;
}
