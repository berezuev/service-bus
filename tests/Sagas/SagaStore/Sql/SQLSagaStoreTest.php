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

namespace Desperado\ServiceBus\Tests\Sagas\SagaStore\Sql;

use Amp\Coroutine;
use function Amp\Promise\wait;
use Desperado\ServiceBus\Sagas\SagaStatus;
use Desperado\ServiceBus\Sagas\SagaStore\Sql\SQLSagaStore;
use Desperado\ServiceBus\Sagas\SagaStore\StoredSaga;
use Desperado\ServiceBus\Infrastructure\Storage\StorageAdapter;
use Desperado\ServiceBus\Infrastructure\Storage\StorageAdapterFactory;
use Desperado\ServiceBus\Tests\Stubs\Sagas\CorrectSaga;
use Desperado\ServiceBus\Tests\Stubs\Sagas\TestSagaId;
use PHPUnit\Framework\TestCase;

/**
 *
 */
final class SQLSagaStoreTest extends TestCase
{
    /**
     * @var StorageAdapter
     */
    private $adapter;

    /**
     * @var SQLSagaStore
     */
    private $store;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = StorageAdapterFactory::inMemory();
        $this->store   = new SQLSagaStore($this->adapter);
    }

    /**
     * @inheritdoc
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->store);
    }

    /**
     * @test
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function flow(): void
    {
        $handler = static function(SQLSagaStoreTest $self): \Generator
        {
            yield $self->adapter->execute(
                (string) \file_get_contents(__DIR__ . '/../../../../src/Sagas/SagaStore/Sql/schema/sagas_store.sql')
            );

            $uuid = '4e77447f-f231-4b07-9dd4-de512732d683';

            $savedSaga = StoredSaga::fromRow([
                'id'               => $uuid,
                'identifier_class' => TestSagaId::class,
                'saga_class'       => CorrectSaga::class,
                'payload'          => 'qwertyRoot',
                'state_id'         => SagaStatus::STATUS_COMPLETED,
                'created_at'       => '2018-01-01 00:00:00',
                'expiration_date'  => '2018-01-01 00:00:00',
                'closed_at'        => '2019-01-01 00:00:00'
            ]);

            yield $self->store->save(
                $savedSaga,
                static function(): void
                {

                }
            );

            /** @var StoredSaga $loadedSaga */
            $loadedSaga = yield $self->store->load(new TestSagaId($uuid, CorrectSaga::class));

            static::assertEquals($savedSaga->id(), $loadedSaga->id());
            static::assertEquals($savedSaga->idClass(), $loadedSaga->idClass());
            static::assertEquals($savedSaga->sagaClass(), $loadedSaga->sagaClass());
            static::assertEquals($savedSaga->payload(), $loadedSaga->payload());
            static::assertEquals($savedSaga->status(), $loadedSaga->status());
            static::assertEquals($savedSaga->payload(), $loadedSaga->payload());
            static::assertEquals($savedSaga->createdAt(), $loadedSaga->createdAt());
            static::assertEquals($savedSaga->expirationDate(), $loadedSaga->expirationDate());
            static::assertEquals($savedSaga->closedAt(), $loadedSaga->closedAt());

            $toUpdate = StoredSaga::fromRow([
                'id'               => $uuid,
                'identifier_class' => TestSagaId::class,
                'saga_class'       => CorrectSaga::class,
                'payload'          => 'qwertyRoot333',
                'state_id'         => SagaStatus::STATUS_COMPLETED,
                'created_at'       => '2018-01-01 00:00:00',
                'expiration_date'  => '2018-01-01 00:00:00',
                'closed_at'        => '2019-01-01 00:00:00'
            ]);

            yield $self->store->update(
                $toUpdate,
                static function(): void
                {

                }
            );

            /** @var StoredSaga $loadedSaga */
            $loadedSaga = yield $self->store->load(new TestSagaId($uuid, CorrectSaga::class));

            static::assertEquals('qwertyRoot333', $loadedSaga->payload());

            yield $self->store->remove(new TestSagaId($uuid, CorrectSaga::class));

            static::assertNull(
                yield $self->store->load(new TestSagaId($uuid, CorrectSaga::class))
            );
        };

        wait(new Coroutine($handler($this)));
    }
}
