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

use React\Promise\PromiseInterface as ReactPromise;
use function Amp\call;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\Promise;
use Bunny\AbstractClient;
use Bunny\Async\Client;
use Bunny\ClientStateEnum;
use Bunny\Exception\ClientException;
use Bunny\Protocol as AmqpProtocol;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpConnectionConfiguration;
use Desperado\ServiceBus\Infrastructure\Transport\Implementation\Amqp\AmqpQoSConfiguration;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The library (jakubkulhan/bunny) architecture does not allow to expand its functionality correctly
 *
 * @todo: Prepare a pull request including fixes
 */
final class BunnyClientOverride extends Client
{
    /**
     * RabbitMQ connection details
     *
     * @var AmqpConnectionConfiguration
     */
    private $connectConfig;

    /**
     * Quality Of Service settings
     *
     * @var AmqpQoSConfiguration
     */
    private $qosConfig;

    /**
     * Read from stream watcher
     *
     * @var string|null
     */
    private $readWatcher;

    /**
     * Write to stream watcher
     *
     * @var string|null
     */
    private $writeWatcher;

    /**
     * Heartbeat watcher
     *
     * @var string|null
     */
    private $heartbeatWatcher;

    /**
     * @noinspection PhpMissingParentConstructorInspection
     *
     * @param AmqpConnectionConfiguration $configuration
     * @param AmqpQoSConfiguration        $qosConfig
     * @param LoggerInterface|null        $log
     */
    public function __construct(
        AmqpConnectionConfiguration $connectConfig,
        AmqpQoSConfiguration $qosConfig,
        LoggerInterface $log = null
    )
    {
        $this->connectConfig = $connectConfig;
        $this->qosConfig     = $qosConfig;

        $parameters = [
            'async'     => true,
            'host'      => $connectConfig->host(),
            'port'      => $connectConfig->port(),
            'vhost'     => $connectConfig->virtualHost(),
            'user'      => $connectConfig->user(),
            'password'  => $connectConfig->password(),
            'timeout'   => $connectConfig->timeout(),
            'heartbeat' => $connectConfig->heartbeatInterval()
        ];

        AbstractClient::__construct($parameters, $log ?? new NullLogger());

        $this->init();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     *
     * @return Promise It does not return any result
     */
    public function connect(): Promise
    {
        if($this->state !== ClientStateEnum::NOT_CONNECTED)
        {
            return new Failure(
                new ClientException('Client already connected/connecting')
            );
        }

        $this->onConnecting();
        $this->writeProtocolHeaders();
        $this->addReadableWatcher();

        return new Coroutine($this->doConnect());
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedArgument
     *
     * @return Promise It does not return any result
     */
    public function disconnect($replyCode = 0, $replyText = ''): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $replyCode, string $replyText): \Generator
            {
                if($this->state === ClientStateEnum::DISCONNECTING)
                {
                    return null;
                }

                if($this->state !== ClientStateEnum::CONNECTED)
                {
                    return yield new Failure(
                        new ClientException('Client is not connected')
                    );
                }

                $this->onDisconnecting();

                if(0 === $replyCode)
                {
                    foreach($this->channels as $channel)
                    {
                        yield $channel->close($replyCode, $replyText);
                    }
                }

                yield $this->connectionClose($replyCode, $replyText, 0, 0);

                $this->cancelReadWatcher();
                $this->cancelWriteWatcher();
                $this->cancelHeartbeatWatcher();
                $this->closeStream();
                $this->init();
            },
            $replyCode, $replyText
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Desperado\ServiceBus\Infrastructure\Transport\Implementation\BunnyRabbitMQ\BunnyChannelOverride>
     */
    public function channel(): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(): \Generator
            {
                try
                {
                    $channelId = $this->findChannelId();

                    $this->channels[$channelId] = new BunnyChannelOverride($this, $channelId);

                    yield $this->channelOpen($channelId);

                    return $this->channels[$channelId];
                }
                catch(\Throwable $throwable)
                {
                    throw new ClientException('channel.open unexpected response', $throwable->getCode(), $throwable);
                }
            }
        );
    }

    /**
     * @inheritdoc
     *
     * @return Promise It does not return any result
     */
    public function onHeartbeat(): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(): \Generator
            {
                /** @var float $currentTime */
                $currentTime = \microtime(true);

                /** @var float|null $lastWrite */
                $lastWrite = $this->lastWrite;

                if(null === $lastWrite)
                {
                    $lastWrite = $currentTime;
                }

                /** @var float $lastWrite */

                /** @var float $nextHeartbeat */
                $nextHeartbeat = $lastWrite + $this->connectConfig->heartbeatInterval();

                if($currentTime >= $nextHeartbeat)
                {
                    $this->writer->appendFrame(new AmqpProtocol\HeartbeatFrame(), $this->writeBuffer);

                    yield $this->flushWriteBuffer();
                }

                unset($currentTime, $lastWrite, $nextHeartbeat);
            }
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodBasicConsumeOkFrame>
     */
    public function consume(
        $channel, $queue = '', $consumerTag = '', $noLocal = false, $noAck = false,
        $exclusive = false, $nowait = false, $arguments = []
    ): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(
                int $channel, string $queue, string $consumerTag, bool $noLocal, bool $noAck,
                bool $exclusive, bool $nowait, array $arguments
            ): \Generator
            {
                $buffer = new AmqpProtocol\Buffer();
                $buffer->appendUint16(60);
                $buffer->appendUint16(20);
                $buffer->appendInt16(0);
                $buffer->appendUint8(\strlen($queue));
                $buffer->append($queue);
                $buffer->appendUint8(\strlen($consumerTag));
                $buffer->append($consumerTag);

                $this->getWriter()->appendBits([$noLocal, $noAck, $exclusive, $nowait], $buffer);
                $this->getWriter()->appendTable($arguments, $buffer);

                $frame              = new AmqpProtocol\MethodFrame(60, 20);
                $frame->channel     = $channel;
                $frame->payloadSize = $buffer->getLength();
                /** @psalm-suppress InvalidPropertyAssignmentValue */
                $frame->payload = $buffer;

                $this->getWriter()->appendFrame($frame, $this->getWriteBuffer());

                yield $this->flushWriteBuffer();

                unset($buffer, $frame);

                return yield $this->awaitConsumeOk($channel);
            },
            $channel, $queue, $consumerTag, $noLocal, $noAck, $exclusive, $nowait, $arguments
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<bool|\Bunny\Protocol\MethodBasicCancelOkFrame>
     */
    public function cancel($channel, $consumerTag, $nowait = false): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, string $consumerTag, bool $nowait): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(6 + \strlen($consumerTag));
                $buffer->appendUint16(60);
                $buffer->appendUint16(30);
                $buffer->appendUint8(\strlen($consumerTag));
                $buffer->append($consumerTag);
                $this->getWriter()->appendBits([$nowait], $buffer);
                $buffer->appendUint8(206);

                if(true === $nowait)
                {
                    return yield $this->flushWriteBuffer();
                }

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitCancelOk($channel);
            },
            $channel, $consumerTag, $nowait
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodBasicGetOkFrame>
     */
    public function get($channel, $queue = '', $noAck = false): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, string $queue, bool $noAck): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(8 + \strlen($queue));
                $buffer->appendUint16(60);
                $buffer->appendUint16(70);
                $buffer->appendInt16(0);
                $buffer->appendUint8(\strlen($queue));
                $buffer->append($queue);
                $this->getWriter()->appendBits([$noAck], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitGetOk($channel);
            },
            $channel, $queue, $noAck
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodBasicQosOkFrame>
     */
    public function qos($channel, $prefetchSize = 0, $prefetchCount = 0, $global = false): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, int $prefetchSize, int $prefetchCount, bool $global): \Generator
            {
                $buffer = $this->getWriteBuffer();

                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(11);
                $buffer->appendUint16(60);
                $buffer->appendUint16(10);
                $buffer->appendInt32($prefetchSize);
                $buffer->appendInt16($prefetchCount);
                $this->getWriter()->appendBits([$global], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitQosOk($channel);
            },
            $channel, $prefetchSize, $prefetchCount, $global
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodConnectionOpenOkFrame>
     */
    public function connectionOpen($virtualHost = '/', $capabilities = '', $insist = false): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(string $virtualHost, string $capabilities, bool $insist): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16(0);
                $buffer->appendUint32(7 + \strlen($virtualHost) + \strlen($capabilities));
                $buffer->appendUint16(10);
                $buffer->appendUint16(40);
                $buffer->appendUint8(\strlen($virtualHost));
                $buffer->append($virtualHost);
                $buffer->appendUint8(\strlen($capabilities));
                $buffer->append($capabilities);
                $this->getWriter()->appendBits([$insist], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitConnectionOpenOk();
            },
            $virtualHost, $capabilities, $insist
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodConnectionCloseOkFrame>
     */
    public function connectionClose($replyCode, $replyText, $closeClassId, $closeMethodId): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $replyCode, string $replyText, int $closeClassId, int $closeMethodId): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16(0);
                $buffer->appendUint32(11 + \strlen($replyText));
                $buffer->appendUint16(10);
                $buffer->appendUint16(50);
                $buffer->appendInt16($replyCode);
                $buffer->appendUint8(\strlen($replyText));
                $buffer->append($replyText);
                $buffer->appendInt16($closeClassId);
                $buffer->appendInt16($closeMethodId);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitConnectionCloseOk();
            },
            $replyCode, $replyText, $closeClassId, $closeMethodId
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodChannelOpenOkFrame>
     */
    public function channelOpen($channel, $outOfBand = ''): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, string $outOfBand): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(5 + \strlen($outOfBand));
                $buffer->appendUint16(20);
                $buffer->appendUint16(10);
                $buffer->appendUint8(\strlen($outOfBand));
                $buffer->append($outOfBand);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return $this->awaitChannelOpenOk($channel);
            },
            $channel, $outOfBand
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodChannelFlowOkFrame>
     */
    public function channelFlow($channel, $active): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, bool $active): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(5);
                $buffer->appendUint16(20);
                $buffer->appendUint16(20);
                $this->getWriter()->appendBits([$active], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitChannelFlowOk($channel);
            },
            $channel, $active
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodAccessRequestOkFrame>
     */
    public function accessRequest($channel, $realm = '/data', $exclusive = false, $passive = true, $active = true, $write = true, $read = true): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, string $realm, bool $exclusive, bool $passive, bool $active, bool $write, bool $read): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(6 + \strlen($realm));
                $buffer->appendUint16(30);
                $buffer->appendUint16(10);
                $buffer->appendUint8(\strlen($realm));
                $buffer->append($realm);
                $this->getWriter()->appendBits([$exclusive, $passive, $active, $write, $read], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitAccessRequestOk($channel);
            },
            $channel, $realm, $exclusive, $passive, $active, $write, $read
        );
    }


    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodExchangeDeclareOkFrame>
     */
    public function exchangeDeclare(
        $channel,
        $exchange,
        $exchangeType = 'direct',
        $passive = false,
        $durable = false,
        $autoDelete = false,
        $internal = false,
        $nowait = false,
        $arguments = []
    ): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(
                int $channel, string $exchange, string $exchangeType, bool $passive, bool $durable,
                bool $autoDelete, bool $internal, bool $nowait, array $arguments
            ): \Generator
            {
                $buffer = new AmqpProtocol\Buffer();
                $buffer->appendUint16(40);
                $buffer->appendUint16(10);
                $buffer->appendInt16(0);
                $buffer->appendUint8(\strlen($exchange));
                $buffer->append($exchange);
                $buffer->appendUint8(\strlen($exchangeType));
                $buffer->append($exchangeType);
                $this->getWriter()->appendBits([$passive, $durable, $autoDelete, $internal, $nowait], $buffer);
                $this->getWriter()->appendTable($arguments, $buffer);

                $frame              = new AmqpProtocol\MethodFrame(40, 10);
                $frame->channel     = $channel;
                $frame->payloadSize = $buffer->getLength();
                /** @psalm-suppress InvalidPropertyAssignmentValue */
                $frame->payload = $buffer;

                $this->getWriter()->appendFrame($frame, $this->getWriteBuffer());

                yield $this->flushWriteBuffer();

                unset($buffer, $frame);

                return yield $this->awaitExchangeDeclareOk($channel);
            },
            $channel, $exchange, $exchangeType, $passive, $durable, $autoDelete, $internal, $nowait, $arguments
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\MethodExchangeDeleteOkFrame>
     */
    public function exchangeDelete($channel, $exchange, $ifUnused = false, $nowait = false): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(int $channel, string $exchange, bool $ifUnused, bool $nowait): \Generator
            {
                $buffer = $this->getWriteBuffer();
                $buffer->appendUint8(1);
                $buffer->appendUint16($channel);
                $buffer->appendUint32(8 + \strlen($exchange));
                $buffer->appendUint16(40);
                $buffer->appendUint16(20);
                $buffer->appendInt16(0);
                $buffer->appendUint8(\strlen($exchange));
                $buffer->append($exchange);
                $this->getWriter()->appendBits([$ifUnused, $nowait], $buffer);
                $buffer->appendUint8(206);

                yield $this->flushWriteBuffer();

                unset($buffer);

                return yield $this->awaitExchangeDeleteOk($channel);
            },
            $channel, $exchange, $ifUnused, $nowait
        );
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<bool>
     */
    protected function flushWriteBuffer(): Promise
    {
        /** @var Promise|null $flushWriteBufferPromise */
        $flushWriteBufferPromise = $this->flushWriteBufferPromise;

        if(null !== $flushWriteBufferPromise)
        {
            return $flushWriteBufferPromise;
        }

        $deferred           = new Deferred();
        $this->writeWatcher = Loop::onWritable(
            $this->getStream(),
            function() use ($deferred): void
            {
                try
                {
                    $this->write();

                    if(true === $this->writeBuffer->isEmpty())
                    {
                        $this->cancelWriteWatcher();
                        $this->flushWriteBufferPromise = null;
                        $deferred->resolve(true);
                    }
                }
                catch(\Exception $e)
                {
                    $this->cancelWriteWatcher();
                    $this->flushWriteBufferPromise = null;
                    $deferred->fail($e);
                }
            }
        );

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        return $this->flushWriteBufferPromise = $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitExchangeDeleteOk($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodExchangeDeleteOkFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            });

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitContentHeader($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\ContentHeaderFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            });

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MissingParamType
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitContentBody($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\ContentBodyFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            });

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitConnectionStart(): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodConnectionStartFrame)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            });

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitConnectionTune(): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodConnectionTuneFrame)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitConnectionOpenOk(): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodConnectionOpenOkFrame)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitConnectionClose(): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred): bool
            {
                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitConnectionCloseOk(): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodConnectionCloseOkFrame)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitChannelOpenOk($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodChannelOpenOkFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    yield $this->qos(
                        $frame->channel,
                        $this->qosConfig->qosSize(),
                        $this->qosConfig->qosCount(),
                        $this->qosConfig->isGlobal()
                    );

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitExchangeDeclareOk($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodExchangeDeclareOkFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @psalm-suppress MissingParamType
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<\Bunny\Protocol\AbstractFrame>
     */
    public function awaitQosOk($channel): Promise
    {
        $deferred = new Deferred();

        $this->addAwaitCallback(
            function(AmqpProtocol\AbstractFrame $frame) use ($deferred, $channel): \Generator
            {
                if($frame instanceof AmqpProtocol\MethodBasicQosOkFrame && $frame->channel === $channel)
                {
                    $deferred->resolve($frame);

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodChannelCloseFrame && $frame->channel === $channel)
                {
                    yield $this->channelCloseOk($channel);

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                if($frame instanceof AmqpProtocol\MethodConnectionCloseFrame)
                {
                    yield $this->connectionCloseOk();

                    $deferred->fail(new ClientException($frame->replyText, $frame->replyCode));

                    return true;
                }

                return false;
            }
        );

        return $deferred->promise();
    }

    /**
     * @inheritdoc
     *
     * @return Promise It does not return any result
     */
    public function onDataAvailable(): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            function(): \Generator
            {
                $this->read();

                while(($frame = $this->reader->consumeFrame($this->readBuffer)) !== null)
                {
                    foreach($this->awaitCallbacks as $k => $callback)
                    {
                        /** @var bool|Promise|ReactPromise|\Generator $executionResult */
                        $executionResult = $callback($frame);

                        /** @var bool $awaitResult */
                        $awaitResult = yield self::adaptAwaitResult($executionResult);

                        if(true === $awaitResult)
                        {
                            /** @psalm-suppress MixedArrayOffset */
                            unset($this->awaitCallbacks[$k]);

                            /** CONTINUE WHILE LOOP */
                            continue 2;
                        }

                        unset($awaitResult, $executionResult);
                    }

                    if(0 === $frame->channel)
                    {
                        $this->onFrameReceived($frame);
                    }
                    else
                    {
                        if(false === isset($this->channels[$frame->channel]))
                        {
                            throw new ClientException(
                                "Received frame #{$frame->type} on closed channel #{$frame->channel}."
                            );
                        }

                        if(false === ($frame instanceof AmqpProtocol\MethodChannelCloseFrame))
                        {
                            $this->channels[$frame->channel]->onFrameReceived($frame);
                        }
                    }

                    unset($frame);
                }
            }
        );
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading
     *
     * @return void
     */
    private function addReadableWatcher(): void
    {
        $this->readWatcher = Loop::onReadable(
            $this->getStream(),
            function(): \Generator
            {
                yield $this->onDataAvailable();
            }
        );
    }

    /**
     * @param bool|Promise|ReactPromise|\Generator $awaitResult
     *
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<bool>
     */
    private static function adaptAwaitResult($awaitResult): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
        /** @psalm-suppress MissingClosureParamType */
            static function($awaitResult): \Generator
            {
                if(true === \is_bool($awaitResult))
                {
                    return $awaitResult;
                }

                if($awaitResult instanceof \Generator)
                {
                    return yield new Coroutine($awaitResult);
                }

                if($awaitResult instanceof ReactPromise)
                {
                    return yield $awaitResult;
                }

                return $awaitResult;
            },
            $awaitResult
        );
    }

    /**
     * Execute connect
     *
     * @psalm-suppress ImplementedReturnTypeMismatch
     * @psalm-suppress InvalidReturnType
     *
     * @return \Generator<null>
     */
    private function doConnect(): \Generator
    {
        yield $this->flushWriteBuffer();

        /** @var AmqpProtocol\MethodConnectionStartFrame $start */
        $start = yield $this->awaitConnectionStart();

        yield $this->authResponse($start);

        /** @var AmqpProtocol\MethodConnectionTuneFrame $tune */
        $tune = yield $this->awaitConnectionTune();

        $this->frameMax = $tune->frameMax;

        if($tune->channelMax > 0)
        {
            $this->channelMax = $tune->channelMax;
        }

        $this->connectionTuneOk($tune->channelMax, $tune->frameMax, $this->options['heartbeat']);
        yield $this->connectionOpen($this->options['vhost']);

        $this->onConnected();
    }

    /**
     * Add timer for heartbeat
     *
     * @return void
     */
    private function addHeartbeatTimer(): void
    {
        /** @var float $seconds */
        $seconds = $this->options['heartbeat'];

        $this->heartbeatWatcher = Loop::repeat(
            (int) ($seconds * 1000),
            function(): \Generator
            {
                yield $this->onHeartbeat();
            }
        );
    }

    /**
     * @return void
     */
    private function cancelHeartbeatWatcher(): void
    {
        if(null !== $this->heartbeatWatcher)
        {
            Loop::cancel($this->heartbeatWatcher);

            $this->heartbeatWatcher = null;
        }
    }

    /**
     * @return void
     */
    private function cancelReadWatcher(): void
    {
        if(null !== $this->readWatcher)
        {
            Loop::cancel($this->readWatcher);

            $this->readWatcher = null;
        }
    }

    /**
     * @return void
     */
    private function cancelWriteWatcher(): void
    {
        if(null !== $this->writeWatcher)
        {
            Loop::cancel($this->writeWatcher);

            $this->writeWatcher = null;
        }
    }

    /**
     * Connection started
     *
     * @return void
     */
    private function onConnecting(): void
    {
        $this->state = ClientStateEnum::DISCONNECTING;
    }

    /**
     * Successful connected
     *
     * @return void
     */
    private function onConnected(): void
    {
        $this->state = ClientStateEnum::CONNECTED;

        $this->addHeartbeatTimer();
    }

    /**
     * Disconnect started
     *
     * @return void
     */
    private function onDisconnecting(): void
    {
        $this->state = ClientStateEnum::DISCONNECTING;

        $this->cancelHeartbeatWatcher();
    }

    /**
     * Add protocol version header
     *
     * @return void
     */
    private function writeProtocolHeaders(): void
    {
        $this->writer->appendProtocolHeader($this->writeBuffer);
    }
}
