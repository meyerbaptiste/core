<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\Common\Util;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;

trait PropertyNameNormalizerTrait
{
    abstract protected function getNameConverter(): ?NameConverterInterface;

    abstract protected function getClassMetadataFactory(): ?ClassMetadataFactoryInterface;

    abstract protected function getClassMetadata(string $resourceClass): ClassMetadata;

    /**
     * @param string $property
     *
     * @return string
     */
    protected function denormalizePropertyName($property/*, string $resourceClass = null*/)
    {
        if (\func_num_args() > 1) {
            $resourceClass = null === ($arg = func_get_arg(1)) ? $arg : (string) $arg;
        } else {
            if (__CLASS__ !== static::class) {
                $r = new \ReflectionMethod($this, __FUNCTION__);
                if (__CLASS__ !== $r->getDeclaringClass()->getName()) {
                    @trigger_error(sprintf('Method %s() will have a second `$resourceClass` argument in version API Platform 3.0. Not defining it is deprecated since API Platform 2.7.', __FUNCTION__), \E_USER_DEPRECATED);
                }
            }

            $resourceClass = null;
        }

        $nameConverter = $this->getNameConverter();
        $denormalizedProperties = [];

        foreach (explode('.', (string) $property) as $subProperty) {
            if ($nameConverter) {
                $subProperty = $nameConverter->denormalize($subProperty);
            }

            if (null === $resourceClass) {
                $denormalizedProperties[] = $subProperty;

                continue;
            }

            $denormalizedProperties[] = $this->getSerializedOriginalAttributeName($resourceClass, $subProperty);

            if (($doctrineClassMetadata = $this->getClassMetadata($resourceClass))->hasAssociation($subProperty)) {
                $resourceClass = $doctrineClassMetadata->getAssociationTargetClass($subProperty);
            } else {
                $resourceClass = null;
            }
        }

        return implode('.', $denormalizedProperties);
    }

    /**
     * @param string $property
     *
     * @return string
     */
    protected function normalizePropertyName($property/*, string $resourceClass = null*/)
    {
        if (\func_num_args() > 1) {
            $resourceClass = null === ($arg = func_get_arg(1)) ? $arg : (string) $arg;
        } else {
            if (__CLASS__ !== static::class) {
                $r = new \ReflectionMethod($this, __FUNCTION__);
                if (__CLASS__ !== $r->getDeclaringClass()->getName()) {
                    @trigger_error(sprintf('Method %s() will have a second `$resourceClass` argument in version API Platform 3.0. Not defining it is deprecated since API Platform 2.7.', __FUNCTION__), \E_USER_DEPRECATED);
                }
            }

            $resourceClass = null;
        }

        $nameConverter = $this->getNameConverter();
        $normalizedProperties = [];

        foreach (explode('.', (string) $property) as $subProperty) {
            $normalizedProperty = $subProperty;

            if (null !== $resourceClass) {
                $normalizedProperty = $this->getSerializedAttributeName($resourceClass, $subProperty);
            }

            if ($nameConverter) {
                $normalizedProperty = $nameConverter->normalize($normalizedProperty);
            }

            if (null !== $resourceClass && ($doctrineClassMetadata = $this->getClassMetadata($resourceClass))->hasAssociation($subProperty)) {
                $resourceClass = $doctrineClassMetadata->getAssociationTargetClass($subProperty);
            } else {
                $resourceClass = null;
            }

            $normalizedProperties[] = $normalizedProperty;
        }

        return implode('.', $normalizedProperties);
    }

    private function getSerializedAttributeName(string $resourceClass, string $originalName): string
    {
        if (!($classMetadataFactory = $this->getClassMetadataFactory()) || !$classMetadataFactory->hasMetadataFor($resourceClass)) {
            return $originalName;
        }

        $attributesMetadata = $classMetadataFactory->getMetadataFor($resourceClass)->getAttributesMetadata();

        if (isset($attributesMetadata[$originalName]) && null !== $serializedName = $attributesMetadata[$originalName]->getSerializedName()) {
            return $serializedName;
        }

        return $originalName;
    }

    private function getSerializedOriginalAttributeName(string $resourceClass, string $serializedName): string
    {
        if (!($classMetadataFactory = $this->getClassMetadataFactory()) || !$classMetadataFactory->hasMetadataFor($resourceClass)) {
            return $serializedName;
        }

        foreach ($classMetadataFactory->getMetadataFor($resourceClass)->getAttributesMetadata() as $attributeMetadata) {
            if ($serializedName === $attributeMetadata->getSerializedName()) {
                return $attributeMetadata->getName();
            }
        }

        return $serializedName;
    }
}
