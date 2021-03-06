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

namespace Desperado\ServiceBus\Infrastructure\Storage\SQL\AmpPostgreSQL;

use Amp\Postgres\Transaction as AmpTransaction;
use function Amp\call;
use Amp\Promise;
use Desperado\ServiceBus\Infrastructure\Storage\TransactionAdapter;

/**
 *  Async PostgreSQL transaction adapter
 */
final class AmpPostgreSQLTransactionAdapter implements TransactionAdapter
{
    /**
     * Original transaction object
     *
     * @var AmpTransaction
     */
    private $transaction;

    /**
     * @param AmpTransaction $transaction
     */
    public function __construct(AmpTransaction $transaction)
    {
        $this->transaction = $transaction;
    }

    /**
     * @inheritdoc
     */
    public function execute(string $queryString, array $parameters = []): Promise
    {
        $transaction = $this->transaction;

        /** @psalm-suppress InvalidArgument */
        return call(
            static function(string $queryString, array $parameters = []) use ($transaction): \Generator
            {
                try
                {
                    /** @var \Amp\Sql\Statement $statement */
                    $statement = yield $transaction->prepare($queryString);

                    /** @var \Amp\Postgres\PooledResultSet $result */
                    $result = yield $statement->execute($parameters);

                    unset($statement);

                    return new AmpPostgreSQLResultSet($result);
                }
                    // @codeCoverageIgnoreStart
                catch(\Throwable $throwable)
                {
                    throw AmpExceptionConvert::do($throwable);
                }
                // @codeCoverageIgnoreEnd
            },
            $queryString,
            $parameters
        );
    }

    /**
     * @inheritdoc
     */
    public function commit(): Promise
    {
        $transaction = $this->transaction;

        /** @psalm-suppress InvalidArgument */
        return call(
            static function() use ($transaction): \Generator
            {
                try
                {
                    yield $transaction->commit();

                    $transaction->close();
                }
                    // @codeCoverageIgnoreStart
                catch(\Throwable $throwable)
                {
                    throw AmpExceptionConvert::do($throwable);
                }
                // @codeCoverageIgnoreEnd
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function rollback(): Promise
    {
        $transaction = $this->transaction;

        /** @psalm-suppress InvalidArgument */
        return call(
            static function() use ($transaction): \Generator
            {
                try
                {
                    yield $transaction->rollback();

                    unset($transaction);
                }
                    // @codeCoverageIgnoreStart
                catch(\Throwable $throwable)
                {
                    /** We will not throw an exception */
                }
                // @codeCoverageIgnoreEnd
            }
        );
    }
}
