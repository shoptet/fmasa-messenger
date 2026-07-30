<?php

declare(strict_types=1);

namespace Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final class HandlerWithMultipleMethodsForSameMessage
{
    #[AsMessageHandler]
    public function first(Message $message): ?string
    {
        return 'first result';
    }

    #[AsMessageHandler]
    public function second(Message $message): ?string
    {
        return 'second result';
    }
}
