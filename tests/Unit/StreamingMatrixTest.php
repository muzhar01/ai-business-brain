<?php

namespace Tests\Unit;

use App\Brain\Drivers\OpenAiCompatibleDriver;
use App\Channels\Slack;
use App\Security\Sso;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

/**
 * Network packets split SSE frames at arbitrary byte offsets; the reader must
 * reassemble every split identically. Plus Slack signature edge cases.
 */
class StreamingMatrixTest extends TestCase
{
    protected static function body(): string
    {
        return "data: {\"choices\":[{\"delta\":{\"content\":\"Héllo \"}}]}\n\n"
            .": keep-alive comment\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"wörld 🌍\"}}]}\r\n\r\n"
            ."event: ping\ndata: {\"choices\":[{\"delta\":{\"content\":\"!\"}}]}\n\n"
            ."data: [DONE]\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"IGNORED\"}}]}\n\n";
    }

    /** Stream that returns the body in fixed-size pieces. */
    protected static function chunked(string $body, int $size): StreamInterface
    {
        return new class($body, $size) implements StreamInterface
        {
            private int $pos = 0;

            public function __construct(private string $b, private int $n) {}

            public function read(int $length): string
            {
                $s = substr($this->b, $this->pos, min($length, $this->n));
                $this->pos += strlen($s);

                return $s;
            }

            public function eof(): bool
            {
                return $this->pos >= strlen($this->b);
            }

            public function __toString(): string
            {
                return $this->b;
            }

            public function close(): void {}

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->b);
            }

            public function tell(): int
            {
                return $this->pos;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function getContents(): string
            {
                return substr($this->b, $this->pos);
            }

            public function getMetadata(?string $key = null)
            {
                return null;
            }
        };
    }

    public static function chunkSizes(): array
    {
        return array_combine(array_map(fn ($n) => "chunk {$n}", range(1, 120)), array_map(fn ($n) => [$n], range(1, 120)));
    }

    #[DataProvider('chunkSizes')]
    public function test_sse_reassembles_any_split(int $size): void
    {
        $driver = new class('openai', ['base_url' => 'http://x', 'model' => 'm']) extends OpenAiCompatibleDriver
        {
            public function parse(Response $r): array
            {
                return iterator_to_array($this->events($r), false);
            }
        };
        $psr = new \GuzzleHttp\Psr7\Response(200, [], self::chunked(self::body(), $size));
        $events = $driver->parse(new Response($psr));

        $this->assertSame(['Héllo ', 'wörld 🌍', '!'], array_map(fn ($e) => $e['choices'][0]['delta']['content'], $events));
    }

    public static function signatureCases(): array
    {
        $now = time();
        $body = '{"type":"event_callback"}';
        $good = 'v0='.hash_hmac('sha256', "v0:{$now}:{$body}", 's');
        $cases = [
            'valid' => [$body, (string) $now, $good, 's', true],
            'wrong secret' => [$body, (string) $now, $good, 't', false],
            'tampered body' => [$body.' ', (string) $now, $good, 's', false],
            'missing sig' => [$body, (string) $now, null, 's', false],
            'missing ts' => [$body, null, $good, 's', false],
            'missing secret' => [$body, (string) $now, $good, null, false],
            'non-numeric ts' => [$body, '12a', $good, 's', false],
            'v1 prefix' => [$body, (string) $now, 'v1='.substr($good, 3), 's', false],
            'uppercase hex' => [$body, (string) $now, strtoupper($good), 's', false],
        ];
        foreach ([-400, -240, 0, 240, 400, -3600, 86400] as $skew) {
            $ts = (string) ($now + $skew);
            $cases["skew {$skew}s"] = [$body, $ts, 'v0='.hash_hmac('sha256', "v0:{$ts}:{$body}", 's'), 's', abs($skew) <= 300];
        }

        return $cases;
    }

    #[DataProvider('signatureCases')]
    public function test_slack_signature(string $body, ?string $ts, ?string $sig, ?string $secret, bool $ok): void
    {
        $this->assertSame($ok, Slack::validSignature($body, $ts, $sig, $secret));
    }

    public static function domains(): array
    {
        $valid = ['acme.com', 'sub.acme.co.uk', 'a-b.io', 'x1.dev', 'ACME.COM'];
        $invalid = ['acme', '-acme.com', 'acme..com', 'http://acme.com', 'acme.com/', 'a b.com', '@acme.com', 'acme.com;drop', '*.acme.com', ''];
        $public = ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com', 'proton.me', 'live.com'];
        $out = [];
        foreach ($valid as $d) {
            $out["ok {$d}"] = [$d, true];
        }
        foreach ($invalid as $d) {
            $out["bad '{$d}'"] = [$d, $d === ''];
        }
        foreach ($public as $d) {
            $out["public {$d}"] = [$d, false];
        }

        return $out;
    }

    public function test_sso_provider_list_is_closed(): void
    {
        $this->assertSame(['google', 'microsoft', 'github'], array_keys(Sso::PROVIDERS));
    }
}
