<?php

namespace App\Services\Ai;

/**
 * Something that can rewrite a piece of text.
 *
 * DELIBERATELY THIN. One method, two strings in, one string out. Every feature a
 * vendor SDK would tempt us into — tools, streaming, structured output,
 * embeddings, conversation history — is absent because the only thing Lapispro asks
 * an AI to do is reword a paragraph it already wrote itself.
 *
 * Controllers never see this interface. They talk to the reporting layer, which
 * is where the guarantees live; this is the small hole in the wall through which
 * text leaves the building.
 */
interface AiTextProvider
{
    /** A stable identifier for the audit trail — the driver, not the vendor's marketing name. */
    public function name(): string;

    /** Which model answers. Recorded on every suggestion so an old audit row stays interpretable. */
    public function model(): string;

    /**
     * @throws AiRequestFailed when the engine refuses, errors, times out or answers with nothing usable.
     */
    public function complete(AiTextRequest $request): AiTextResponse;
}
