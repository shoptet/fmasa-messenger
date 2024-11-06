<?php

declare(strict_types=1);

namespace Fmasa\Messenger\Tracy;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tracy\Debugger;
use Tracy\Dumper;
use Tracy\Helpers;

use function array_map;
use function count;
use function explode;
use function get_class;
use function implode;
use function microtime;
use function round;

final class LogToPanelMiddleware implements MiddlewareInterface
{
    private string $busName;

    private ?bool $enabled;

    /** @var HandledMessage[] */
    private array $handledMessages = [];

    public function __construct(string $busName, ?bool $enabled = null)
    {
        $this->busName = $busName;
        $this->enabled = $enabled;
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $time = microtime(true);

        $result = $stack->next()->handle($envelope, $stack);

        if (!$this->isEnabled()) {
            return $result;
        }

        $time = microtime(true) - $time;

        $this->handledMessages[] = new HandledMessage(
            $this->getMessageName($envelope),
            round($time * 1000, 3),
            self::dump($envelope->getMessage()),
            implode(
                "\n",
                array_map(
                    fn (HandledStamp $stamp) => $this->dump($stamp->getResult()),
                    $result->all(HandledStamp::class),
                ),
            )
        );

        return $result;
    }

    public function getBusName(): string
    {
        return $this->busName;
    }

    /**
     * @return HandledMessage[]
     */
    public function getHandledMessages(): array
    {
        return $this->handledMessages;
    }

    private function getMessageName(Envelope $envelope): string
    {
        $nameParts = explode('\\', get_class($envelope->getMessage()));

        return $nameParts[count($nameParts) - 1];
    }

    private function isEnabled(): bool
    {
        if ($this->enabled === null) {
            $this->enabled = class_exists(Debugger::class)
                && Debugger::isEnabled()
                && Debugger::$productionMode !== true;
        }

        return $this->enabled;
    }

    private function dump(mixed $value): string
    {
        $options = [
            Dumper::DEPTH => Debugger::$maxDepth,
            Dumper::TRUNCATE => Debugger::$maxLength,
            Dumper::ITEMS => Debugger::$maxItems,
            Dumper::DEBUGINFO => true,
            Dumper::LOCATION => false,
        ];

        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg'
            ? Dumper::toTerminal($value, $options)
            : Helpers::capture(static fn () => Dumper::dump($value, $options));
    }
}
