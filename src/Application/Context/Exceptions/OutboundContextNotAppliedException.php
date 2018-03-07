<?php

/**
 * PHP Service Bus (CQS implementation)
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\ServiceBus\Application\Context\Exceptions;

/**
 * The context of sending messages is not set
 */
class OutboundContextNotAppliedException extends OutboundContextException
{
    public function __construct()
    {
        parent::__construct(
            'You need to set the context for sending messages to the transport layer.'
        );
    }
}
