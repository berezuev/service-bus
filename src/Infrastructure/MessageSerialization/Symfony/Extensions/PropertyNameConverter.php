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

namespace Desperado\ServiceBus\Infrastructure\MessageSerialization\Symfony\Extensions;

use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

/**
 * Convert snake_case to lowerCamelCase
 */
final class PropertyNameConverter implements NameConverterInterface
{
    /**
     * @inheritdoc
     */
    public function normalize($propertyName): string
    {
        return $propertyName;
    }

    /**
     * @inheritdoc
     */
    public function denormalize($propertyName): string
    {
        /** @var string $result */
        $result = \preg_replace_callback(
            '/_(.?)/',
            static function(array $matches): string
            {
                return \ucfirst((string) $matches[1]);
            },
            $propertyName
        );

        return \lcfirst($result);
    }
}
