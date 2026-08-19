<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\AiTextRequest;
use App\Services\Ai\AiTextResponse;
use Closure;

/**
 * An engine that answers whatever the test told it to (§42).
 *
 * IT LIVES IN app/, NOT tests/, for two reasons. The factory has to be able to
 * build it from a config value, so `LAPIS_AI_DRIVER=fake` gives a developer the
 * whole flow — button, modes, preview, accept — without an account anywhere. And
 * the guards this module exists for can only be tested by an engine that
 * misbehaves ON PURPOSE: changes a percentage, invents a difficulty, adds a
 * strategy nobody chose, times out. A recorded HTTP fixture cannot be asked to
 * do that on demand.
 *
 * It refuses to run in production. A fake that quietly echoed text back on a
 * live installation would look exactly like a feature that works.
 *
 * DEFAULT BEHAVIOUR IS TO ECHO. An engine that returns the text unchanged is the
 * honest null case: a valid answer that improves nothing.
 */
class FakeAiTextProvider implements AiTextProvider
{
    /** @var list<Closure(AiTextRequest): AiTextResponse> */
    protected array $scripted = [];

    /** @var list<AiTextRequest> Everything it was asked, in order, for assertions. */
    public array $received = [];

    public function __construct(protected string $model = 'fake-model') {}

    public function name(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }

    /** The next call answers with exactly this text. */
    public function willReturn(string $text): self
    {
        return $this->willReturnUsing(fn (): string => $text);
    }

    /**
     * The next call answers with whatever the callback makes of the request.
     *
     * @param  Closure(AiTextRequest): string  $answer
     */
    public function willReturnUsing(Closure $answer): self
    {
        $this->scripted[] = fn (AiTextRequest $request): AiTextResponse => new AiTextResponse(
            text: $answer($request),
            provider: $this->name(),
            model: $this->model,
            inputTokens: mb_strlen($request->content),
            outputTokens: mb_strlen($request->content),
            latencyMilliseconds: 1,
        );

        return $this;
    }

    /** The next call throws. Use the named constructors on AiRequestFailed. */
    public function willFail(AiRequestFailed $failure): self
    {
        $this->scripted[] = function () use ($failure): AiTextResponse {
            throw $failure;
        };

        return $this;
    }

    /**
     * The next call answers with the text it was given, minus one thing.
     *
     * A shorthand for the commonest guard test: an engine that drops a
     * placeholder is an engine that dropped a fact.
     */
    public function willDrop(string $fragment): self
    {
        return $this->willReturnUsing(
            fn (AiTextRequest $request): string => str_replace($fragment, '', $request->content),
        );
    }

    /** The next call answers with the text it was given, plus a sentence nobody wrote. */
    public function willAppend(string $sentence): self
    {
        return $this->willReturnUsing(
            fn (AiTextRequest $request): string => rtrim($request->content).' '.$sentence,
        );
    }

    public function complete(AiTextRequest $request): AiTextResponse
    {
        $this->received[] = $request;

        $next = array_shift($this->scripted);

        if ($next !== null) {
            return $next($request);
        }

        return new AiTextResponse(
            text: $request->content,
            provider: $this->name(),
            model: $this->model,
            inputTokens: mb_strlen($request->content),
            outputTokens: mb_strlen($request->content),
            latencyMilliseconds: 1,
        );
    }

    /** What it was asked last, for tests that assert on what left the building. */
    public function lastRequest(): ?AiTextRequest
    {
        return $this->received === [] ? null : $this->received[array_key_last($this->received)];
    }
}
