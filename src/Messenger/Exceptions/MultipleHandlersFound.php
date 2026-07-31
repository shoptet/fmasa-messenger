<?php

declare(strict_types=1);

namespace Fmasa\Messenger\Exceptions;

use Exception;
use Nette\DI\Definitions\Definition;

use function array_map;
use function implode;
use function sprintf;

final class MultipleHandlersFound extends Exception
{
    /**
     * @param array<array{0: Definition, 1: string}> $handlers
     */
    public static function fromHandlerClasses(string $messageName, array $handlers): self
    {
        return new self(sprintf(
            'There are multiple handlers for message "%s": %s',
            $messageName,
            implode(
                ', ',
                array_map(
                    static function (array $handler): string {
                        [$definition, $methodName] = $handler;

                        return sprintf('%s::%s (%s)', $definition->getName(), $methodName, $definition->getType());
                    },
                    $handlers
                )
            )
        ));
    }
}
