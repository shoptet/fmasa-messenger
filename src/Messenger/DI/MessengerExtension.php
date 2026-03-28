<?php

declare(strict_types=1);

namespace Fmasa\Messenger\DI;

use Fmasa\Messenger\Exceptions\InvalidHandlerService;
use Fmasa\Messenger\Exceptions\MultipleHandlersFound;
use Fmasa\Messenger\LazyHandlersLocator;
use Fmasa\Messenger\Tracy\LogToPanelMiddleware;
use Fmasa\Messenger\Tracy\MessengerPanel;
use Fmasa\Messenger\Transport\SendersLocator;
use Fmasa\Messenger\Transport\TaggedServiceLocator;
use LogicException;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\EventListener\DispatchPcntlSignalListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnSigtermSignalListener;
use Symfony\Component\Messenger\Handler\BatchHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\InMemoryTransportFactory as LegacyInMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_fill_keys;
use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function class_exists;
use function count;
use function get_object_vars;
use function interface_exists;
use function is_a;
use function is_array;
use function is_callable;
use function is_int;
use function is_string;
use function krsort;
use function sprintf;

class MessengerExtension extends CompilerExtension
{
    private const TAG_HANDLER           = 'messenger.messageHandler';
    private const TAG_TRANSPORT_FACTORY = 'messenger.transportFactory';
    private const TAG_RECEIVER_ALIAS    = 'messenger.receiver.alias';
    private const TAG_BUS_NAME          = 'messenger.bus.name';
    private const TAG_RETRY_STRATEGY    = 'messenger.retryStrategy';
    private const TAG_FAILURE_TRANSPORT = 'messenger.failureTransport';

    private const HANDLERS_LOCATOR_SERVICE_NAME = '.handlersLocator';
    private const PANEL_MIDDLEWARE_SERVICE_NAME = '.middleware.panel';
    private const PANEL_SERVICE_NAME            = 'panel';

    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'serializer' => Expect::from(new SerializerConfig()),
            'buses' => Expect::arrayOf(Expect::from(new BusConfig())),
            'transports' => Expect::arrayOf(Expect::anyOf(
                Expect::string(),
                Expect::from(new TransportConfig())
            )),
            'failureTransport' => Expect::string()->nullable(),
            'routing' => Expect::arrayOf(
                Expect::anyOf(Expect::string(), Expect::listOf(Expect::string()))
            ),
        ]);
    }

    public function loadConfiguration(): void
    {
        $builder = $this->getContainerBuilder();

        $this->compiler->addExportedTag(SendersLocator::TAG_SENDER_ALIAS);
        $this->compiler->addExportedTag(self::TAG_RETRY_STRATEGY);
        $this->compiler->addExportedTag(self::TAG_FAILURE_TRANSPORT);
        $this->compiler->addExportedTag(self::TAG_RECEIVER_ALIAS);
        $this->compiler->addExportedTag(self::TAG_BUS_NAME);

        $this->processTransports();
        $this->processRouting();
        $this->processBuses();
        $this->processConsoleCommands();

        if (! $this->isPanelEnabledForAnyBus()) {
            return;
        }

        $builder->addDefinition($this->prefix(self::PANEL_SERVICE_NAME))
            ->setType(MessengerPanel::class);
    }

    /**
     * @throws InvalidHandlerService
     * @throws MultipleHandlersFound
     */
    public function beforeCompile(): void
    {
        $config  = $this->getConfig();
        $builder = $this->getContainerBuilder();

        foreach ($config->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);
            $handlers = [];

            foreach ($this->getHandlerDefinitionsForBus($busName) as $messageName => $handlerDefinitions) {
                foreach ($handlerDefinitions as $handlerDefinition) {
                    $handlers[$messageName][$handlerDefinition->serviceName] = $handlerDefinition;
                }
            }

            if ($busConfig->singleHandlerPerMessage) {
                foreach ($handlers as $messageName => $handlerDefinitions) {
                    if (count($handlerDefinitions) > 1) {
                        throw MultipleHandlersFound::fromHandlerClasses(
                            $messageName,
                            array_map($builder->getDefinition(...), array_keys($handlerDefinitions)),
                        );
                    }
                }
            }

            $handlersLocator = $this->getContainerBuilder()
                ->getDefinition($this->prefix($busName . self::HANDLERS_LOCATOR_SERVICE_NAME));

            assert($handlersLocator instanceof ServiceDefinition);

            $handlersLocator->setArguments([$handlers]);
        }

        $this->setupEventDispatcher();
        $this->passRegisteredTransportFactoriesToMainFactory();

        if (! $this->isPanelMiddlewareRegistered()) {
            return;
        }

        $panel = $builder->getDefinition($this->prefix(self::PANEL_SERVICE_NAME));

        assert($panel instanceof ServiceDefinition);

        $panel->setArguments([$this->getContainerBuilder()->findByType(LogToPanelMiddleware::class)]);
    }

    public function afterCompile(ClassType $class): void
    {
        if (! $this->isPanelMiddlewareRegistered()) {
            return;
        }

        $this->enableTracyIntegration($class);
    }

    private function processBuses(): void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->getConfig()->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);

            $middleware = [];

            if ($busConfig->panel) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . self::PANEL_MIDDLEWARE_SERVICE_NAME))
                    ->setFactory(LogToPanelMiddleware::class, [$busName]);
            }

            foreach ($busConfig->middleware as $index => $middlewareDefinition) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . '.middleware.' . $index))
                    ->setFactory($middlewareDefinition);
            }

            $handlersLocator = $builder->addDefinition($this->prefix($busName . self::HANDLERS_LOCATOR_SERVICE_NAME))
                ->setFactory(LazyHandlersLocator::class);

            $middleware[] = $builder->addDefinition($this->prefix($busName . '.sendMiddleware'))
                ->setFactory(SendMessageMiddleware::class);

            $middleware[] = $builder->addDefinition($this->prefix($busName . '.defaultMiddleware'))
                ->setFactory(HandleMessageMiddleware::class, [$handlersLocator, $busConfig->allowNoHandlers]);

            $builder->addDefinition($this->prefix($busName . '.bus'))
                ->setFactory(MessageBus::class, [$middleware])
                ->setTags([self::TAG_BUS_NAME => $busName]);
        }
    }

    /**
     * @return Statement[]
     */
    private function getSubscribers(): array
    {
        $subscribers = [
            new Statement(DispatchPcntlSignalListener::class),
            new Statement(
                SendFailedMessageForRetryListener::class,
                [
                    new Statement(TaggedServiceLocator::class, [SendersLocator::TAG_SENDER_ALIAS]),
                    new Statement(TaggedServiceLocator::class, [self::TAG_RETRY_STRATEGY]),
                ]
            ),
            new Statement(
                SendFailedMessageToFailureTransportListener::class,
                [new Statement(TaggedServiceLocator::class, [self::TAG_FAILURE_TRANSPORT])]
            ),
        ];

        if (class_exists(StopWorkerOnSigtermSignalListener::class)) {
            $subscribers[] = new Statement(StopWorkerOnSigtermSignalListener::class);
        }

        return $subscribers;
    }

    private function processConsoleCommands(): void
    {
        $builder = $this->getContainerBuilder();

        $routableBus = $builder->addDefinition($this->prefix('busLocator'))
            ->setAutowired(false)
            ->setFactory(
                RoutableMessageBus::class,
                [new Statement(TaggedServiceLocator::class, [self::TAG_BUS_NAME]), null]
            );

        $receiverLocator = $builder->addDefinition($this->prefix('console.receiversLocator'))
            ->setFactory(TaggedServiceLocator::class, [self::TAG_RECEIVER_ALIAS])
            ->setAutowired(false);

        $builder->addDefinition($this->prefix('console.command.consumeMessages'))
            ->setFactory(ConsumeMessagesCommand::class, [$routableBus, $receiverLocator]);
    }

    private function processTransports(): void
    {
        $builder = $this->getContainerBuilder();

        $transportFactory = $builder->addDefinition($this->prefix('transportFactory'))
            ->setFactory(TransportFactory::class);

        foreach ($this->getDefaultFactories() as $name => $factoryClass) {
            $builder->addDefinition($this->prefix('transportFactory.' . $name))
                ->setFactory($factoryClass)
                ->setTags([self::TAG_TRANSPORT_FACTORY => true]);
        }

        $serializerConfig = $this->getConfig()->serializer;
        assert($serializerConfig instanceof SerializerConfig);

        $defaultSerializer = $builder->addDefinition($this->prefix('defaultSerializer'))
            ->setType(SerializerInterface::class)
            ->setFactory($serializerConfig->defaultSerializer);

        $failureTransports = [];

        foreach ($this->getConfig()->transports as $transportName => $transportConfig) {
            assert(is_string($transportConfig) || $transportConfig instanceof TransportConfig);

            $failureTransportName = $transportConfig->failureTransport ?? $this->getConfig()->failureTransport;
            if ($failureTransportName !== null) {
                $failureTransports[$failureTransportName][] = $transportName;
            }

            if (is_string($transportConfig)) {
                $dsn        = $transportConfig;
                $options    = [];
                $serializer = $defaultSerializer;
            } else {
                $dsn        = $transportConfig->dsn;
                $options    = $transportConfig->options;
                $serializer = $transportConfig->serializer !== null
                    ? $builder->addDefinition($this->prefix('serializer.' . $transportName))
                        ->setType(SerializerInterface::class)
                        ->setFactory($transportConfig->serializer)
                    : $defaultSerializer;
            }

            $transportServiceName = $this->prefix('transport.' . $transportName);

            $builder->addDefinition($transportServiceName)
                ->setFactory([$transportFactory, 'createTransport'], [$dsn, $options, $serializer])
                ->setTags([
                    SendersLocator::TAG_SENDER_ALIAS => $transportName,
                    self::TAG_RECEIVER_ALIAS => $transportName,
                ]);
        }

        foreach ($failureTransports as $failureTransportName => $transportNames) {
            $builder->getDefinition($this->prefix('transport.' . $failureTransportName))
                ->addTag(self::TAG_FAILURE_TRANSPORT, $transportNames);
        }
    }

    /**
     * @return array<string, class-string>
     */
    private function getDefaultFactories(): array
    {
        return [
            'amqp' => AmqpTransportFactory::class,
            'inMemory' => class_exists(InMemoryTransportFactory::class)
                ? InMemoryTransportFactory::class
                : LegacyInMemoryTransportFactory::class, // @phpstan-ignore class.notFound
            'redis' => RedisTransportFactory::class,
        ];
    }

    private function processRouting(): void
    {
        $this->getContainerBuilder()->addDefinition($this->prefix('sendersLocator'))
            ->setFactory(
                SendersLocator::class,
                [
                    array_map(
                        static fn ($oneOrManyTransports) => is_string($oneOrManyTransports)
                            ? [$oneOrManyTransports]
                            : $oneOrManyTransports,
                        $this->getConfig()->routing
                    ),
                ]
            );
    }

    /**
     * @return iterable<string, iterable<HandlerDefinition>>
     *
     * @throws InvalidHandlerService
     */
    private function getHandlerDefinitionsForBus(string $busName): iterable
    {
        $builder                     = $this->getContainerBuilder();
        $handlerDefinitionsByMessage = [];

        $handlerDefinitionsByMessageAndPriority = $this->mergeHandlerDefinitions(array_map(
            fn (Definition $serviceDefinition) => $this->getHandlerDefinitionsForService($serviceDefinition, $busName),
            $builder->getDefinitions(),
        ));

        foreach ($handlerDefinitionsByMessageAndPriority as $message => $handlerDefinitionsByPriority) {
            krsort($handlerDefinitionsByPriority);
            $handlerDefinitionsByMessage[$message] = array_merge(...$handlerDefinitionsByPriority);
        }

        return $handlerDefinitionsByMessage;
    }

    /**
     * @return array<string, array<int, HandlerDefinition[]>>
     */
    private function getHandlerDefinitionsForService(Definition $definition, string $busName): array
    {
        static $asMessageHandlerExists;
        static $messageHandlerInterfaceExists;
        static $batchHandlerInterfaceExists;
        $asMessageHandlerExists        ??= class_exists(AsMessageHandler::class);
        $messageHandlerInterfaceExists ??= interface_exists(MessageHandlerInterface::class);
        $batchHandlerInterfaceExists   ??= interface_exists(BatchHandlerInterface::class);

        $handlerClass = $definition->getType();

        if ($handlerClass === null) {
            return [];
        }

        assert(class_exists($handlerClass) || interface_exists($handlerClass));

        if ($asMessageHandlerExists) {
            $handlerReflection = new ReflectionClass($handlerClass);
            $configs           = $this->extractConfigsFromAttributes($handlerReflection);

            if ($configs !== []) {
                return $this->mergeHandlerDefinitions(array_map(
                    fn (array $config) => $this->createHandlerDefinitions(
                        $config,
                        $handlerReflection,
                        $definition->getName(),
                        $busName,
                    ),
                    $configs,
                ));
            }
        }

        $config = $definition->getTag(self::TAG_HANDLER);

        if (
            $config !== null
            || ($messageHandlerInterfaceExists && is_a($handlerClass, MessageHandlerInterface::class, true))
            || ($batchHandlerInterfaceExists && is_a($handlerClass, BatchHandlerInterface::class, true))
        ) {
            return $this->createHandlerDefinitions(
                is_array($config) ? $config : [],
                new ReflectionClass($handlerClass),
                $definition->getName(),
                $busName,
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed>    $config
     * @param ReflectionClass<object> $handlerReflection
     *
     * @return array<string, array<int, HandlerDefinition[]>>
     */
    private function createHandlerDefinitions(
        array $config,
        ReflectionClass $handlerReflection,
        string $serviceName,
        string $busName,
    ): array {
        $bus     = $config['bus'] ?? null;
        $handles = $config['handles'] ?? null;
        $method  = $config['method'] ?? null;
        $handles = $handles !== null
            ? $method !== null ? [$handles => $method] : [$handles]
            : $this->guessHandledClasses($handlerReflection, $serviceName, $method ?? '__invoke');

        $handlerDefinitions = [];

        foreach ($handles as $message => $options) {
            if (is_int($message)) {
                $message = (string) $options;
                $options = [];
            }

            if (is_string($options)) {
                $options = ['method' => $options];
            }

            if (($options['bus'] ?? $bus ?? $busName) !== $busName) {
                continue;
            }

            $options['from_transport'] ??= $config['from_transport'] ?? null;

            $handlerMethod = $options['method'] ?? '__invoke';

            if (! $handlerReflection->hasMethod($handlerMethod)) {
                throw InvalidHandlerService::missingHandlerMethod(
                    $serviceName,
                    $handlerReflection->name,
                    $handlerMethod,
                );
            }

            $options['alias'] ??= $config['alias'] ?? null;

            $priority = $config['priority'] ?? $options['priority'] ?? 0;

            $handlerDefinitions[$message][$priority][] = new HandlerDefinition(
                $serviceName,
                $handlerMethod,
                $options,
            );
        }

        return $handlerDefinitions;
    }

    /**
     * @param array<array<string, array<int, HandlerDefinition[]>>> $handlerDefinitionsByMessageAndPriorityList
     *
     * @return array<string, array<int, HandlerDefinition[]>>
     */
    private function mergeHandlerDefinitions(array $handlerDefinitionsByMessageAndPriorityList): array
    {
        $mergedHandlerDefinitions = [];

        foreach ($handlerDefinitionsByMessageAndPriorityList as $handlerDefinitionsByMessageAndPriority) {
            foreach ($handlerDefinitionsByMessageAndPriority as $message => $handlerDefinitionsByPriority) {
                foreach ($handlerDefinitionsByPriority as $priority => $handlerDefinitions) {
                    $mergedHandlerDefinitions[$message][$priority] = array_merge(
                        $mergedHandlerDefinitions[$message][$priority] ?? [],
                        $handlerDefinitions,
                    );
                }
            }
        }

        return $mergedHandlerDefinitions;
    }

    /**
     * @param ReflectionClass<object> $handlerReflection
     *
     * @return array<string, mixed>[]
     */
    private function extractConfigsFromAttributes(ReflectionClass $handlerReflection): array
    {
        $configs = array_map(
            $this->extractConfigFromAttribute(...),
            $handlerReflection->getAttributes(AsMessageHandler::class),
        );

        foreach ($handlerReflection->getMethods() as $reflectionMethod) {
            foreach ($reflectionMethod->getAttributes(AsMessageHandler::class) as $reflectionAttribute) {
                $config = $this->extractConfigFromAttribute($reflectionAttribute);

                if (isset($config['method'])) {
                    throw new LogicException(sprintf(
                        'AsMessageHandler attribute cannot declare a method on "%s::%s()".',
                        $handlerReflection,
                        $reflectionMethod->getName(),
                    ));
                }

                $config['method'] = $reflectionMethod->getName();

                $configs[] = $config;
            }
        }

        return $configs;
    }

    /**
     * @param ReflectionAttribute<AsMessageHandler> $attributeReflection
     *
     * @return array<string, mixed>
     */
    private function extractConfigFromAttribute(ReflectionAttribute $attributeReflection): array
    {
        $config = get_object_vars($attributeReflection->newInstance());

        $config['from_transport'] = $config['fromTransport'] ?? null;
        unset($config['fromTransport']);

        return $config;
    }

    /**
     * @param ReflectionClass<object> $handlerReflection
     *
     * @return iterable<string>
     *
     * @throws InvalidHandlerService
     */
    private function guessHandledClasses(ReflectionClass $handlerReflection, string $serviceName, string $methodName): iterable
    {
        $handlerClassName = $handlerReflection->getName();
        static $messageSubscriberExists;
        $messageSubscriberExists ??= interface_exists(MessageSubscriberInterface::class);

        if ($messageSubscriberExists && $handlerReflection->implementsInterface(MessageSubscriberInterface::class)) {
            $getHandledMessages = [$handlerClassName, 'getHandledMessages'];

            if (is_callable($getHandledMessages)) {
                return $getHandledMessages();
            }
        }

        try {
            $method = $handlerReflection->getMethod($methodName);
        } catch (ReflectionException $e) {
            throw InvalidHandlerService::missingHandlerMethod($serviceName, $handlerClassName, $methodName);
        }

        if ($method->getNumberOfRequiredParameters() !== 1) {
            throw InvalidHandlerService::wrongAmountOfArguments($serviceName, $handlerClassName, $methodName);
        }

        $parameter     = $method->getParameters()[0];
        $parameterName = $parameter->getName();
        $type          = $parameter->getType();

        if ($type === null) {
            throw InvalidHandlerService::missingArgumentType($serviceName, $handlerClassName, $methodName, $parameterName);
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw InvalidHandlerService::invalidArgumentType($serviceName, $handlerClassName, $methodName, $parameterName, $type);
        }

        if ($type instanceof ReflectionUnionType) {
            $types        = [];
            $invalidTypes = [];
            foreach ($type->getTypes() as $type) {
                if ($type instanceof ReflectionIntersectionType) {
                    throw InvalidHandlerService::invalidArgumentType($serviceName, $handlerClassName, $methodName, $parameterName, $type);
                }

                if (! $type->isBuiltin()) {
                    $types[] = (string) $type;
                } else {
                    $invalidTypes[] = (string) $type;
                }
            }

            if (count($types) > 0) {
                return $methodName === '__invoke' ? $types : array_fill_keys($types, $methodName);
            }

            throw InvalidHandlerService::invalidArgumentUnionType($serviceName, $handlerClassName, $methodName, $parameterName, $invalidTypes);
        }

        assert($type instanceof ReflectionNamedType);

        if ($type->isBuiltin()) {
            throw InvalidHandlerService::invalidArgumentType($serviceName, $handlerClassName, $methodName, $parameterName, $type);
        }

        return $methodName === '__invoke' ? [$type->getName()] : [$type->getName() => $methodName];
    }

    private function enableTracyIntegration(ClassType $class): void
    {
        $class->getMethod('initialize')->addBody($this->getContainerBuilder()->formatPhp('?;', [
            new Statement(
                '@Tracy\Bar::addPanel',
                [new Statement('@' . $this->prefix(self::PANEL_SERVICE_NAME))]
            ),
        ]));
    }

    private function isPanelMiddlewareRegistered(): bool
    {
        return $this->getContainerBuilder()->findByType(LogToPanelMiddleware::class) !== [];
    }

    private function isPanelEnabledForAnyBus(): bool
    {
        foreach ($this->getConfig()->buses as $busConfig) {
            assert($busConfig instanceof BusConfig);
            if ($busConfig->panel) {
                return true;
            }
        }

        return false;
    }

    private function setupEventDispatcher(): void
    {
        $builder = $this->getContainerBuilder();

        $eventDispatcherServiceName = $builder->getByType(EventDispatcherInterface::class);

        if ($eventDispatcherServiceName === null) {
            $eventDispatcher = $builder->addDefinition($this->prefix('console.eventDispatcher'))
                ->setFactory(EventDispatcher::class)
                ->setAutowired(false);

            $consumeMessagesCommand = $builder->getDefinition($this->prefix('console.command.consumeMessages'));
            assert($consumeMessagesCommand instanceof ServiceDefinition);

            if (! isset($consumeMessagesCommand->getFactory()->arguments[2])) {
                $consumeMessagesCommand->getFactory()->arguments[2] = $eventDispatcher;
            }
        } else {
            $eventDispatcher = $builder->getDefinition($eventDispatcherServiceName);
        }

        assert($eventDispatcher instanceof ServiceDefinition);

        foreach ($this->getSubscribers() as $subscriber) {
            $eventDispatcher->addSetup('addSubscriber', [$subscriber]);
        }
    }

    private function passRegisteredTransportFactoriesToMainFactory(): void
    {
        $builder = $this->getContainerBuilder();

        $transportFactory = $builder->getDefinition($this->prefix('transportFactory'));
        assert($transportFactory instanceof ServiceDefinition);

        $transportFactory->setArguments([
            array_map([$builder, 'getDefinition'], array_keys($builder->findByTag(self::TAG_TRANSPORT_FACTORY))),
        ]);
    }
}
