<?php

/**
 * Command Query Responsibility Segregation, Event Sourcing implementation
 *
 * @author  Maksim Masiukevich <desperado@minsk-info.ru>
 * @url     https://github.com/mmasiukevich
 * @license MIT
 * @license https://opensource.org/licenses/MIT
 */

declare(strict_types = 1);

namespace Desperado\Framework\Infrastructure\EventSourcing\Annotation;

use Desperado\Framework\Domain\Annotation\AbstractAnnotation;

/**
 * Saga listener marker
 *
 * @Annotation
 * @Target("METHOD")
 */
class SagaListener extends AbstractAnnotation
{
    /**
     * The event property that contains the saga ID
     *
     * @var string
     */
    public $containingIdentityProperty;

    /**
     * Saga identity class namespace
     *
     * @var string
     */
    public $identityNamespace;
}