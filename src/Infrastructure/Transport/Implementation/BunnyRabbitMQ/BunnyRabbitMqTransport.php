<?php

/**
 * PHP Service Bus (publish-subscribe pattern implementation)
 * Supports Saga pattern and Event Sourcing
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Infrastructure\Transport\Implementation\BunnyRabbitMQ;

use function Amp\call;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Emitter;
use Amp\Loop;
use Amp\Promise;
use Desperado\ServiceBus\Infrastructure\Transport\Exceptions\BindFailed;
use Desperado\ServiceBus\Infrastructure\Transport\Exceptions\ConnectionFail;
use Desperado\ServiceBus\Infrastructure\Transport\Exceptions\CreateQueueFailed;
use Desperado\ServiceBus\Infrastructure\Transport\Exceptions\CreateTopicFailed;
use Desperado\ServiceBus\Infrastructure\Transport\Exceptions\SendMessageFailed;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpConnectionConfiguration;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpExchange;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpQoSConfiguration;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpQueue;
use Desperado\ServiceBus\Infrastructure\Transport\Package\OutboundPackage;
use Desperado\ServiceBus\Infrastructure\Transport\Queue;
use Desperado\ServiceBus\Infrastructure\Transport\QueueBind;
use Desperado\ServiceBus\Infrastructure\Transport\Topic;
use Desperado\ServiceBus\Infrastructure\Transport\TopicBind;
use Desperado\ServiceBus\Infrastructure\Transport\Transport;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A non-blocking concurrency RabbitMQ transport
 *
 * @see https://github.com/jakubkulhan/bunny
 */
final class BunnyRabbitMqTransport implements Transport
{
    private const AMQP_DURABLE = 2;

    /**
     * RabbitMQ connection details
     *
     * @var AmqpConnectionConfiguration
     */
    private $connectionConfig;

    /**
     * Client for work with AMQP protocol
     *
     * @var BunnyClientOverride
     */
    private $client;

    /**
     * Channel client
     *
     * Null if not connected
     *
     * @var BunnyChannelOverride|null
     */
    private $channel;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var bool
     */
    private $isConnected = false;

    /**
     * @var array<string, \Desperado\ServiceBus\Infrastructure\Transport\Implementation\BunnyRabbitMQ\BunnyConsumer>
     */
    private $consumers;

    /**
     * @param AmqpConnectionConfiguration $connectionConfig
     * @param AmqpQoSConfiguration|null   $qosConfig
     * @param LoggerInterface|null        $logger
     */
    public function __construct(
        AmqpConnectionConfiguration $connectionConfig,
        AmqpQoSConfiguration $qosConfig = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->connectionConfig = $connectionConfig;
        $this->logger           = $logger ?? new NullLogger();

        $this->client = new BunnyClientOverride(
            $this->connectionConfig,
            $qosConfig ?? new AmqpQoSConfiguration(),
            $this->logger
        );
    }

    /**
     * @inheritDoc
     */
    public function connect(): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(): \Generator
            {
                if(true === $this->isConnected)
                {
                    return;
                }

                try
                {
                    yield $this->client->connect();

                    /** @var BunnyChannelOverride $channel */
                    $channel = yield $this->client->channel();

                    $this->channel     = $channel;
                    $this->isConnected = true;

                    $this->logger->info('Connected to {transportHost}:{transportPort}', [
                            'transportHost' => $this->connectionConfig->host(),
                            'transportPort' => $this->connectionConfig->port()
                        ]
                    );

                    unset($channel);
                }
                catch(\Throwable $throwable)
                {
                    throw new ConnectionFail($throwable->getMessage(), $throwable->getCode(), $throwable);
                }
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function disconnect(): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(): \Generator
            {
                if(true === $this->isConnected)
                {
                    try
                    {
                        yield $this->client->disconnect();
                    }
                    catch(\Throwable $throwable)
                    {
                        /** Not interested */
                    }

                    $this->isConnected = false;
                }
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function consume(Queue $queue): Promise
    {
        /** @var AmqpQueue $queue */

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(AmqpQueue $queue): \Generator
            {
                yield $this->connect();

                /** @var BunnyChannelOverride $channel */
                $channel = $this->channel;

                $emitter  = new Emitter();
                $consumer = new BunnyConsumer($queue, $channel, $this->logger);

                $consumer->listen(
                    static function(BunnyIncomingPackage $incomingPackage) use ($emitter): \Generator
                    {
                        yield $emitter->emit($incomingPackage);
                    }
                );

                $this->consumers[(string) $queue] = $consumer;

                unset($consumer, $channel);

                return $emitter->iterate();

            },
            $queue
        );
    }

    /**
     * @inheritDoc
     */
    public function send(OutboundPackage $outboundPackage): Promise
    {
        /** @var BunnyChannelOverride $channel */
        $channel  = $this->channel;
        $logger   = $this->logger;
        $deferred = new Deferred();

        Loop::defer(
            static function() use ($channel, $outboundPackage, $logger, $deferred): \Generator
            {
                try
                {
                    /** @var \Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpTransportLevelDestination $destination */
                    $destination = $outboundPackage->destination();
                    $headers     = \array_filter(\array_merge($outboundPackage->headers(), [
                        'delivery-mode' => true === $outboundPackage->isPersistent() ? self::AMQP_DURABLE : null,
                        'expiration'    => $outboundPackage->expiredAfter()
                    ]));

                    $content = yield $outboundPackage->payload()->read();

                    $logger->debug('Publish message to "{rabbitMqExchange}" with routing key "{rabbitMqRoutingKey}"', [
                        'operationId'        => $outboundPackage->traceId(),
                        'rabbitMqExchange'   => $destination->exchange(),
                        'rabbitMqRoutingKey' => $destination->routingKey(),
                        'content'            => $content,
                        'headers'            => $headers,
                        'isMandatory'        => $outboundPackage->isMandatory(),
                        'isImmediate'        => $outboundPackage->isImmediate(),
                        'expiredAt'          => $outboundPackage->expiredAfter()
                    ]);

                    yield $channel->publish(
                        $content,
                        \array_filter($headers),
                        $destination->exchange(),
                        $destination->routingKey(),
                        $outboundPackage->isMandatory(),
                        $outboundPackage->isImmediate()
                    );

                    unset($destination, $headers);

                    $deferred->resolve(true);
                }
                catch(\Throwable $throwable)
                {
                    $deferred->fail(
                        new SendMessageFailed($throwable->getMessage(), $throwable->getCode(), $throwable)
                    );
                }
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritDoc
     */
    public function stop(Queue $queue): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(Queue $queue): \Generator
            {
                $queueName = (string) $queue;

                if(true === isset($this->consumers[$queueName]))
                {
                    /** @var BunnyConsumer $consumer */
                    $consumer = $this->consumers[$queueName];

                    yield $consumer->stop();

                    unset($consumer, $this->consumers[$queueName]);
                }
            },
            $queue
        );
    }

    /**
     * @inheritDoc
     */
    public function createTopic(Topic $topic, TopicBind ...$binds): Promise
    {
        /** @var AmqpExchange $topic */

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(AmqpExchange $exchange, array $binds): \Generator
            {
                yield $this->connect();

                /** @var BunnyChannelOverride $channel */
                $channel = $this->channel;

                yield new Coroutine(self::doCreateExchange($channel, $exchange));
                /** @psalm-suppress MixedTypeCoercion */
                yield new Coroutine(self::doBindExchange($channel, $exchange, $binds));

                unset($channel);
            },
            $topic, $binds
        );
    }

    /**
     * @inheritDoc
     */
    public function createQueue(Queue $queue, QueueBind ...$binds): Promise
    {
        /** @var AmqpQueue $queue */

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(AmqpQueue $queue, array $binds): \Generator
            {
                yield $this->connect();

                /** @var BunnyChannelOverride $channel */
                $channel = $this->channel;

                yield new Coroutine(self::doCreateQueue($channel, $queue));
                /** @psalm-suppress MixedTypeCoercion */
                yield new Coroutine(self::doBindQueue($channel, $queue, $binds));

                unset($channel);
            },
            $queue, $binds
        );
    }

    /**
     * Execute exchange creation
     *
     * @param BunnyChannelOverride $channel
     * @param AmqpExchange         $exchange
     *
     * @return \Generator
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Transport\Exceptions\CreateTopicFailed
     */
    private static function doCreateExchange(BunnyChannelOverride $channel, AmqpExchange $exchange): \Generator
    {
        try
        {
            yield $channel->exchangeDeclare(
                (string) $exchange, $exchange->type(), $exchange->isPassive(), $exchange->isDurable(),
                false, false, false, $exchange->arguments()
            );
        }
        catch(\Throwable $throwable)
        {
            throw new CreateTopicFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }

    /**
     * Bind exchange to another exchange(s)
     *
     * @param BunnyChannelOverride                                                   $channel
     * @param LoggerInterface                                                        $logger
     * @param AmqpExchange                                                           $exchange
     * @param array<mixed, \Desperado\ServiceBus\Infrastructure\Transport\TopicBind> $binds
     *
     * @return \Generator
     */
    private static function doBindExchange(BunnyChannelOverride $channel, AmqpExchange $exchange, array $binds): \Generator
    {
        try
        {
            foreach($binds as $bind)
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Transport\TopicBind $bind */

                /** @var AmqpExchange $sourceExchange */
                $sourceExchange = $bind->topic();

                yield new Coroutine(self::doCreateExchange($channel, $sourceExchange));
                yield $channel->exchangeBind((string) $sourceExchange, (string) $exchange, (string) $bind->routingKey());
            }
        }
        catch(\Throwable $throwable)
        {
            throw new BindFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }

    /**
     * Execute queue creation
     *
     * @param BunnyChannelOverride $channel
     * @param AmqpQueue            $queue
     *
     * @return \Generator
     */
    private static function doCreateQueue(BunnyChannelOverride $channel, AmqpQueue $queue): \Generator
    {
        try
        {
            yield $channel->queueDeclare(
                (string) $queue, $queue->isPassive(), $queue->isDurable(), $queue->isExclusive(),
                $queue->autoDeleteEnabled(), false, $queue->arguments()
            );
        }
        catch(\Throwable $throwable)
        {
            throw new CreateQueueFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }

    /**
     * Bind queue to exchange(s)
     *
     * @param BunnyChannelOverride                                                   $channel
     * @param AmqpQueue                                                              $queue
     * @param array<mixed, \Desperado\ServiceBus\Infrastructure\Transport\QueueBind> $binds
     *
     * @return \Generator
     */
    private static function doBindQueue(BunnyChannelOverride $channel, AmqpQueue $queue, array $binds): \Generator
    {
        try
        {
            foreach($binds as $bind)
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Transport\QueueBind $bind */

                /** @var AmqpExchange $destinationExchange */
                $destinationExchange = $bind->topic();

                yield new Coroutine(self::doCreateExchange($channel, $destinationExchange));

                yield $channel->queueBind((string) $queue, (string) $destinationExchange, (string) $bind->routingKey());
            }
        }
        catch(\Throwable $throwable)
        {
            throw new BindFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
        }
    }
}
