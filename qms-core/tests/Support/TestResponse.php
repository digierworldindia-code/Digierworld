<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Response;
use PHPUnit\Framework\Assert;

/**
 * Assertions on a response produced by HttpTestTrait.
 */
final class TestResponse
{
    public function __construct(private readonly Response $response)
    {
    }

    public function response(): Response
    {
        return $this->response;
    }

    public function assertStatus(int $status): self
    {
        Assert::assertSame($status, $this->response->getStatusCode(), 'HTTP status; body: ' . mb_substr(strip_tags($this->response->getBody()), 0, 300));

        return $this;
    }

    public function assertOK(): self
    {
        return $this->assertStatus(200);
    }

    public function assertRedirectTo(string $url): self
    {
        Assert::assertContains($this->response->getStatusCode(), [301, 302, 303, 307], 'expected a redirect');
        Assert::assertSame($url, $this->response->getHeaderLine('Location'));

        return $this;
    }

    public function assertSee(string $text): self
    {
        Assert::assertStringContainsString($text, $this->response->getBody());

        return $this;
    }

    public function getJSON(): mixed
    {
        return $this->response->getJSON();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }
}
