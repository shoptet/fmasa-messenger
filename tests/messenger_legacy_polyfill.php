<?php

declare(strict_types=1);

// phpcs:disable Squiz.Classes.ClassFileName
// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

namespace Symfony\Component\Messenger\Handler;

use function interface_exists;

if (! interface_exists(MessageHandlerInterface::class)) {
    interface MessageHandlerInterface
    {
    }
}

if (! interface_exists(MessageSubscriberInterface::class)) {
    interface MessageSubscriberInterface extends MessageHandlerInterface
    {
        /**
         * @return iterable<mixed>
         */
        public static function getHandledMessages(): iterable;
    }
}
