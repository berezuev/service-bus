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

namespace Desperado\ServiceBus\EventSourcing\EventStreamStore\Sql;

use function Amp\call;
use Amp\Promise;
use Desperado\ServiceBus\EventSourcing\EventStreamStore\Exceptions\NonUniqueStreamId;
use Desperado\ServiceBus\EventSourcing\EventStreamStore\Exceptions\SaveStreamFailed;
use function Latitude\QueryBuilder\field;
use Desperado\ServiceBus\EventSourcing\Aggregate;
use Desperado\ServiceBus\EventSourcing\AggregateId;
use Desperado\ServiceBus\EventSourcing\EventStreamStore\AggregateStore;
use Desperado\ServiceBus\EventSourcing\EventStreamStore\StoredAggregateEvent;
use Desperado\ServiceBus\EventSourcing\EventStreamStore\StoredAggregateEventStream;
use function Desperado\ServiceBus\Infrastructure\Storage\fetchAll;
use function Desperado\ServiceBus\Infrastructure\Storage\fetchOne;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\insertQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\equalsCriteria;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\selectQuery;
use function Desperado\ServiceBus\Infrastructure\Storage\SQL\updateQuery;
use Desperado\ServiceBus\Infrastructure\Storage\StorageAdapter;
use Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter;
use Desperado\ServiceBus\Infrastructure\Storage\Exceptions\UniqueConstraintViolationCheckFailed;

/**
 * Events storage backend (SQL-based)
 */
final class SqlEventStreamStore implements AggregateStore
{
    /**
     * @var StorageAdapter
     */
    private $adapter;

    /**
     * @param StorageAdapter $adapter
     */
    public function __construct(StorageAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * @inheritdoc
     */
    public function saveStream(StoredAggregateEventStream $aggregateEventStream): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StoredAggregateEventStream $eventsStream) use ($adapter): \Generator
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
                $transaction = yield $adapter->transaction();

                try
                {
                    yield self::doSaveStream($transaction, $eventsStream);
                    yield self::doSaveEvents($transaction, $eventsStream);

                    yield $transaction->commit();
                }
                catch(UniqueConstraintViolationCheckFailed $exception)
                {
                    yield $transaction->rollback();

                    throw new NonUniqueStreamId(
                        $eventsStream->aggregateId(),
                        $eventsStream->getAggregateIdClass()
                    );
                }
                catch(\Throwable $throwable)
                {
                    yield $transaction->rollback();

                    throw new SaveStreamFailed($throwable->getMessage(), $throwable->getCode(), $throwable);
                }
                finally
                {
                    unset($transaction);
                }
            },
            $aggregateEventStream
        );
    }

    /**
     * @inheritdoc
     */
    public function appendStream(StoredAggregateEventStream $aggregateEventStream): promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StoredAggregateEventStream $eventsStream) use ($adapter): \Generator
            {
                /** @var \Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter $transaction */
                $transaction = yield $adapter->transaction();

                try
                {
                    yield self::doSaveEvents($transaction, $eventsStream);

                    yield $transaction->commit();
                }
                catch(\Throwable $throwable)
                {
                    yield $transaction->rollback();

                    throw $throwable;
                }
                finally
                {
                    unset($transaction);
                }
            },
            $aggregateEventStream
        );
    }

    /**
     * @inheritdoc
     */
    public function loadStream(
        AggregateId $id,
        int $fromVersion = Aggregate::START_PLAYHEAD_INDEX,
        ?int $toVersion = null
    ): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(AggregateId $id, int $fromVersion, ?int $toVersion) use ($adapter): \Generator
            {
                $aggregateEventStream = null;

                /** @var array<string, string>|null $streamData */
                $streamData = yield self::doLoadStream($adapter, $id);

                if(null !== $streamData)
                {
                    $streamEventsData = yield self::doLoadStreamEvents(
                        $adapter,
                        (string) $streamData['id'],
                        $fromVersion,
                        $toVersion
                    );

                    $aggregateEventStream = self::restoreEventStream($adapter, $streamData, $streamEventsData);
                }

                unset($streamData, $streamEventsData);

                return $aggregateEventStream;
            },
            $id, $fromVersion, $toVersion
        );
    }

    /**
     * @inheritdoc
     */
    public function closeStream(AggregateId $id): Promise
    {
        $adapter = $this->adapter;

        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(AggregateId $id) use ($adapter): \Generator
            {
                /**
                 * @var \Latitude\QueryBuilder\Query\UpdateQuery $updateQuery
                 *
                 * @psalm-suppress ImplicitToStringCast
                 */
                $updateQuery = updateQuery('event_store_stream', ['closed_at' => \date('Y-m-d H:i:s')])
                    ->where(equalsCriteria('id', $id))
                    ->andWhere(equalsCriteria('identifier_class', \get_class($id)));

                /** @var \Latitude\QueryBuilder\Query $compiledQuery */
                $compiledQuery = $updateQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $adapter->execute($compiledQuery->sql(), $compiledQuery->params());

                unset($resultSet, $updateQuery);
            },
            $id
        );
    }

    /**
     * Store the parent event stream
     *
     * @param TransactionAdapter         $transaction
     * @param StoredAggregateEventStream $eventsStream
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @return Promise It does not return any result
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\OperationFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\UniqueConstraintViolationCheckFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed
     */
    private static function doSaveStream(TransactionAdapter $transaction, StoredAggregateEventStream $eventsStream): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StoredAggregateEventStream $eventsStream) use ($transaction): \Generator
            {
                /** @var \Latitude\QueryBuilder\Query\InsertQuery $insertQuery */
                $insertQuery = insertQuery('event_store_stream', [
                    'id'               => $eventsStream->aggregateId(),
                    'identifier_class' => $eventsStream->getAggregateIdClass(),
                    'aggregate_class'  => $eventsStream->aggregateClass(),
                    'created_at'       => $eventsStream->createdAt(),
                    'closed_at'        => $eventsStream->closedAt()
                ]);

                /** @var \Latitude\QueryBuilder\Query $compiledQuery */
                $compiledQuery = $insertQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $transaction->execute($compiledQuery->sql(), $compiledQuery->params());

                unset($resultSet, $insertQuery, $compiledQuery);
            },
            $eventsStream
        );
    }

    /**
     * Saving events in stream
     *
     * @param TransactionAdapter         $transaction
     * @param StoredAggregateEventStream $eventsStream
     *
     * @return Promise It does not return any result
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\OperationFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\UniqueConstraintViolationCheckFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed
     */
    private static function doSaveEvents(TransactionAdapter $transaction, StoredAggregateEventStream $eventsStream): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StoredAggregateEventStream $eventsStream) use ($transaction): \Generator
            {
                $eventsCount = \count($eventsStream->aggregateEvents());

                if(0 !== $eventsCount)
                {
                    yield $transaction->execute(
                        self::createSaveEventQueryString($eventsCount),
                        self::collectSaveEventQueryParameters($eventsStream)
                    );
                }

                unset($eventsCount);
            },
            $eventsStream
        );
    }

    /**
     * Create a sql query to store events
     *
     * @param int $eventsCount
     *
     * @return string
     */
    private static function createSaveEventQueryString(int $eventsCount): string
    {
        return \sprintf(
        /** @lang text */
            'INSERT INTO event_store_stream_events (id, stream_id, playhead, event_class, payload, occured_at, recorded_at) VALUES %s',
            \implode(
                ', ', \array_fill(0, $eventsCount, '(?, ?, ?, ?, ?, ?, ?)')
            )
        );
    }

    /**
     * Gathering parameters for sending to a request to save events
     *
     * @param StoredAggregateEventStream $eventsStream
     *
     * @return array
     */
    private static function collectSaveEventQueryParameters(StoredAggregateEventStream $eventsStream): array
    {
        $queryParameters = [];
        $rowSetIndex     = 0;

        /** @var array<int, string|int> $parameters */
        foreach(self::prepareEventRows($eventsStream) as $parameters)
        {
            foreach($parameters as $parameter)
            {
                $queryParameters[$rowSetIndex] = $parameter;

                $rowSetIndex++;
            }
        }

        return $queryParameters;
    }

    /**
     * Prepare events to insert
     *
     * @param StoredAggregateEventStream $eventsStream
     *
     * @return array<int, array<int, string|int>>
     */
    private static function prepareEventRows(StoredAggregateEventStream $eventsStream): array
    {
        $eventsRows = [];

        foreach($eventsStream->aggregateEvents() as $storedAggregateEvent)
        {
            /** @var StoredAggregateEvent $storedAggregateEvent */

            $row = [
                $storedAggregateEvent->eventId(),
                $eventsStream->aggregateId(),
                $storedAggregateEvent->playheadPosition(),
                $storedAggregateEvent->eventClass(),
                $storedAggregateEvent->eventData(),
                $storedAggregateEvent->occuredAt(),
                \date('Y-m-d H:i:s')
            ];

            $eventsRows[] = $row;
        }

        return $eventsRows;
    }

    /**
     * Execute load event stream
     *
     * @param StorageAdapter $adapter
     * @param AggregateId    $id
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<array<string, string>|null>
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\OperationFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed
     */
    private static function doLoadStream(StorageAdapter $adapter, AggregateId $id): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StorageAdapter $adapter, AggregateId $id): \Generator
            {
                /**
                 * @var \Latitude\QueryBuilder\Query\SelectQuery $selectQuery
                 *
                 * @psalm-suppress ImplicitToStringCast
                 */
                $selectQuery = selectQuery('event_store_stream')
                    ->where(equalsCriteria('id', $id))
                    ->andWhere(equalsCriteria('identifier_class', \get_class($id)));

                /** @var \Latitude\QueryBuilder\Query $compiledQuery */
                $compiledQuery = $selectQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $adapter->execute($compiledQuery->sql(), $compiledQuery->params());

                /** @var array<string, string>|null $data */
                $data = yield fetchOne($resultSet);

                unset($resultSet, $selectQuery, $compiledQuery);

                return $data;
            },
            $adapter,
            $id
        );
    }

    /**
     * Load events for specified stream
     *
     * @param StorageAdapter $adapter
     * @param string         $streamId
     * @param int            $fromVersion
     * @param int|null       $toVersion
     *
     * @psalm-suppress MoreSpecificReturnType Incorrect resolving the value of the promise
     * @psalm-suppress LessSpecificReturnStatement Incorrect resolving the value of the promise
     * @psalm-suppress MixedTypeCoercion
     *
     * @return Promise<array<int, array>>
     *
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\ConnectionFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\OperationFailed
     * @throws \Desperado\ServiceBus\Infrastructure\Storage\Exceptions\StorageInteractingFailed
     */
    private static function doLoadStreamEvents(
        StorageAdapter $adapter,
        string $streamId,
        int $fromVersion,
        ?int $toVersion
    ): Promise
    {
        /** @psalm-suppress InvalidArgument Incorrect psalm unpack parameters (...$args) */
        return call(
            static function(StorageAdapter $adapter, string $streamId, int $fromVersion, ?int $toVersion): \Generator
            {
                /** @var \Latitude\QueryBuilder\Query\SelectQuery $selectQuery */
                $selectQuery = selectQuery('event_store_stream_events')
                    ->where(field('stream_id')->eq($streamId))
                    ->andWhere(field('playhead')->gte($fromVersion));

                if(null !== $toVersion && $fromVersion < $toVersion)
                {
                    $selectQuery->andWhere(field('playhead')->lte($toVersion));
                }

                /** @var \Latitude\QueryBuilder\Query $compiledQuery */
                $compiledQuery = $selectQuery->compile();

                /** @var \Desperado\ServiceBus\Infrastructure\Storage\ResultSet $resultSet */
                $resultSet = yield $adapter->execute($compiledQuery->sql(), $compiledQuery->params());

                $result = yield fetchAll($resultSet);

                unset($resultSet);

                return $result;
            },
            $adapter,
            $streamId,
            $fromVersion,
            $toVersion
        );
    }

    /**
     * Transform events stream array data to stored representation
     *
     * @param StorageAdapter         $adapter
     * @param array<string, string>  $streamData
     * @param array<int, array>|null $streamEventsData
     *
     * @return StoredAggregateEventStream
     */
    private static function restoreEventStream(
        StorageAdapter $adapter,
        array $streamData,
        ?array $streamEventsData
    ): StoredAggregateEventStream
    {
        return new StoredAggregateEventStream(
            $streamData['id'],
            $streamData['identifier_class'],
            $streamData['aggregate_class'],
            self::restoreEvents($adapter, $streamEventsData),
            $streamData['created_at'],
            $streamData['closed_at']
        );
    }

    /**
     * Restore events from rows
     *
     * @param StorageAdapter $adapter
     * @param array|null     $eventsData
     *
     * @return array<int, \Desperado\ServiceBus\EventSourcing\EventStreamStore\StoredAggregateEvent>
     */
    private static function restoreEvents(StorageAdapter $adapter, ?array $eventsData): array
    {
        $events = [];

        if(true === \is_array($eventsData) && 0 !== \count($eventsData))
        {
            /** @var array<string, string> $eventRow */
            foreach($eventsData as $eventRow)
            {
                $playhead = (int) $eventRow['playhead'];

                $events[$playhead] = new StoredAggregateEvent(
                    $eventRow['id'],
                    $playhead,
                    $adapter->unescapeBinary($eventRow['payload']),
                    $eventRow['event_class'],
                    $eventRow['occured_at'],
                    $eventRow['recorded_at']
                );
            }
        }

        return $events;
    }
}
