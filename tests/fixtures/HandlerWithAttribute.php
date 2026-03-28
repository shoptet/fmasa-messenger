<?php

declare(strict_types=1);

namespace Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class HandlerWithAttribute
{
    private string $result;

    public function __construct(string $result)
    {
        $this->result = $result;
    }

    public function __invoke(Message $message): ?string
    {
        return $this->result;
    }
}
