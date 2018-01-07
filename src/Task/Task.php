<?php

/**
 * PHP Service Bus (CQS implementation)
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Task;

use Desperado\Domain\Message\AbstractMessage;
use Desperado\ServiceBus\Services\Handlers\Messages\AbstractMessageExecutionParameters;
use Desperado\ServiceBus\MessageProcessor\AbstractExecutionContext;

/**
 * The task to be executed
 */
class Task implements TaskInterface
{
    /**
     * Message handler
     *
     * @var \Closure
     */
    private $executionHandler;

    /**
     * Task-specific parameters
     *
     * @var AbstractMessageExecutionParameters
     */
    private $options;

    /**
     * Create a new task
     *
     * @param \Closure    $executionHandler
     * @param AbstractMessageExecutionParameters $options
     *
     * @return Task
     */
    public static function new(\Closure $executionHandler, AbstractMessageExecutionParameters $options): self
    {
        $self = new self();

        $self->executionHandler = $executionHandler;
        $self->options = $options;

        return $self;
    }

    /**
     * @inheritdoc
     */
    public function getOptions(): AbstractMessageExecutionParameters
    {
        return $this->options;
    }

    /**
     * @inheritdoc
     */
    public function __invoke(AbstractMessage $message, AbstractExecutionContext $context): ?TaskInterface
    {
        return \call_user_func_array($this->executionHandler, [$message, $context]);
    }

    /**
     * Close constructor
     */
    private function __construct()
    {

    }
}
