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

namespace ApiPlatform\Core\Bridge\Doctrine\Orm\Filter;

use ApiPlatform\Core\Bridge\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Core\Bridge\Doctrine\Common\Filter\OrderFilterTrait;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Order the collection by given properties.
 *
 * The ordering is done in the same sequence as they are specified in the query,
 * and for each property a direction value can be specified.
 *
 * For each property passed, if the resource does not have such property or if the
 * direction value is different from "asc" or "desc" (case insensitive), the property
 * is ignored.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Théo FIDRY <theo.fidry@gmail.com>
 */
class OrderFilter extends AbstractContextAwareFilter implements OrderFilterInterface
{
    use OrderFilterTrait;

    /**
     * @param RequestStack|null $requestStack No prefix to prevent autowiring of this deprecated property
     */
    public function __construct(ManagerRegistry $managerRegistry, $requestStack = null, string $orderParameterName = 'order', LoggerInterface $logger = null, array $properties = null)
    {
        if (null !== $properties) {
            $properties = array_map(function ($propertyOptions) {
                // shorthand for default direction
                if (\is_string($propertyOptions)) {
                    $propertyOptions = [
                        'default_direction' => $propertyOptions,
                    ];
                }

                return $propertyOptions;
            }, $properties);
        }

        parent::__construct($managerRegistry, $requestStack, $logger, $properties);

        $this->orderParameterName = $orderParameterName;
    }

    /**
     * {@inheritdoc}
     */
    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null, array $context = [])
    {
        if (isset($context['filters']) && !isset($context['filters'][$this->orderParameterName])) {
            return;
        }

        if (!isset($context['filters'][$this->orderParameterName]) || !\is_array($context['filters'][$this->orderParameterName])) {
            parent::apply($queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);

            return;
        }

        foreach ($context['filters'][$this->orderParameterName] as $property => $value) {
            $this->filterProperty($property, $value, $queryBuilder, $queryNameGenerator, $resourceClass, $operationName, $context);
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function filterProperty(string $property, $direction, QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null/*, array $context = []*/)
    {
        if (\func_num_args() > 6) {
            $context = func_get_arg(6);
        } else {
            if (__CLASS__ !== \get_class($this)) {
                $r = new \ReflectionMethod($this, __FUNCTION__);
                if (__CLASS__ !== $r->getDeclaringClass()->getName()) {
                    @trigger_error(sprintf('Method %s() will have a sixth `$context` argument in version API Platform 3.0. Not defining it is deprecated since API Platform 2.4.', __FUNCTION__), E_USER_DEPRECATED);
                }
            }
            $context = [];
        }

        if (!$this->isPropertyEnabled($property, $resourceClass, $context) || !$this->isPropertyMapped($property, $resourceClass)) {
            return;
        }

        $direction = $this->normalizeValue($direction, $property);
        if (null === $direction) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $field = $property;

        if ($this->isPropertyNested($property, $resourceClass)) {
            list($alias, $field) = $this->addJoinsForNestedProperty($property, $alias, $queryBuilder, $queryNameGenerator, $resourceClass, Join::LEFT_JOIN);
        }

        if (null !== $nullsComparison = $this->properties[$property]['nulls_comparison'] ?? null) {
            $nullsDirection = self::NULLS_DIRECTION_MAP[$nullsComparison][$direction];

            $nullRankHiddenField = sprintf('_%s_%s_null_rank', $alias, $field);

            $queryBuilder->addSelect(sprintf('CASE WHEN %s.%s IS NULL THEN 0 ELSE 1 END AS HIDDEN %s', $alias, $field, $nullRankHiddenField));
            $queryBuilder->addOrderBy($nullRankHiddenField, $nullsDirection);
        }

        $queryBuilder->addOrderBy(sprintf('%s.%s', $alias, $field), $direction);
    }

    /**
     * {@inheritdoc}
     */
    protected function extractProperties(Request $request/*, string $resourceClass*/): array
    {
        @trigger_error(sprintf('The use of "%s::extractProperties()" is deprecated since 2.2. Use the "filters" key of the context instead.', __CLASS__), E_USER_DEPRECATED);
        $properties = $request->query->get($this->orderParameterName);

        return \is_array($properties) ? $properties : [];
    }
}
