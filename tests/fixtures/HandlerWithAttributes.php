<?php

declare(strict_types=1);

namespace Fixtures;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(method: 'handleMessage')]
final class HandlerWithAttributes
{
    public function handleMessage(Message $message): ?string
    {
        return 'message result';
    }

    #[AsMessageHandler]
    public function handleMessage2(Message2 $message): ?string
    {
        return 'message2 result';
    }

    #[AsMessageHandler]
    public function handleMessage3(Message3 $message): ?string
    {
        return 'message3 result';
    }
}
