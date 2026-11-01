<?php

namespace App\Brain\Contracts;

use App\Brain\Data\LlmResponse;

interface LlmDriver
{
    /**
     * @param  array<int, array>  $messages  normalized messages (see App\Brain\Data\Message)
     * @param  array<int, Tool>  $tools
     * @param  array<string, mixed>  $options  temperature, max_tokens, model
     */
    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse;

    /**
     * Same as chat(), but calls $onText with each text fragment as the model produces it.
     *
     * @param  callable(string): void  $onText
     */
    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse;

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array;

    public function supportsEmbeddings(): bool;

    public function model(): string;
}
