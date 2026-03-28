<?php

declare(strict_types=1);

namespace Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'other')]
final class HandlerWithBusOptionViaAttribute
{
    public function __invoke(Message $message): ?string
    {
        return 'message with bus option result';
    }
}
