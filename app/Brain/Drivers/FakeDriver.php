<?php

namespace App\Brain\Drivers;

use App\Brain\Data\LlmResponse;

/** Offline driver for tests and demos: echoes the question with any context it got. */
class FakeDriver extends Driver
{
    public function model(): string
    {
        return 'fake-1';
    }

    public function supportsEmbeddings(): bool
    {
        return true;
    }

    public function chat(array $messages, array $tools = [], array $options = []): LlmResponse
    {
        $last = collect($messages)->where('role', 'user')->last();

        return new LlmResponse('Echo: '.($last['content'] ?? ''), model: 'fake-1');
    }

    public function stream(array $messages, array $tools, array $options, callable $onText): LlmResponse
    {
        $response = $this->chat($messages, $tools, $options);
        foreach (preg_split('/(?<= )/', (string) $response->content) as $piece) {
            $onText($piece);
        }

        return $response;
    }

    /** Deterministic bag-of-words hashing so keyword overlap = similarity. */
    public function embed(array $texts): array
    {
        $dim = 64;

        return array_map(function ($text) use ($dim) {
            $v = array_fill(0, $dim, 0.0);
            foreach (preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $v[crc32($word) % $dim] += 1.0;
            }

            return $v;
        }, array_values($texts));
    }
}
