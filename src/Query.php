<?php

namespace Cake\ElasticSearch;

use Cake\Datasource\QueryTrait;
use Cake\ElasticSearch\ResultSet;
use Cake\ElasticSearch\Type;
use Elastica\Filter\AbstractFilter;
use Elastica\Filter\Bool as BoolFilter;
use Elastica\Query as ElasticaQuery;
use Elastica\Query\Filtered as FilteredQuery;
use IteratorAggregate;

class Query implements IteratorAggregate
{

    use QueryTrait;

    /**
     * Indicates that the operation should append to the list
     *
     * @var integer
     */
    const APPEND = 0;

    /**
     * Indicates that the operation should prepend to the list
     *
     * @var integer
     */
    const PREPEND = 1;

    /**
     * Indicates that the operation should overwrite the list
     *
     * @var boolean
     */
    const OVERWRITE = true;

    protected $_elasticQuery;

    protected $_parts = [
        'fields' => [],
        'preFilter' => null,
        'postFilter' => null,
        'order' => [],
        'limit' => null
    ];

    public function __construct(Type $repository)
    {
        $this->repository($repository);
        $this->_elasticQuery = new ElasticaQuery;
    }

    public function select($fields)
    {
        $this->_parts['fields'] = $fields;
        return $this;
    }

    public function limit($limit)
    {
        $this->_parts['limit'] = (int)$limit;
        return $this;
    }

    /**
    * Sets the sorting options for the result set.
    *
    * The accepted format for the $order param is:
    *
    * - [['name' => ['order'=> 'asc', ...]], ['price' => ['order'=> 'asc', ...]]]
    * - ['name' => 'asc', 'price' => 'desc']
    * - 'field1' (defaults to order => 'desc')
    *
    * @param string|array $order The sorting order to use.
    * @param bool $overwrite Whether or not to replace previous sorting.
    * @return $this
    */
    public function order($order, $overwrite = false)
    {
        // [['field' => [...]], ['field2' => [...]]]
        if (is_array($order) && is_numeric(key($order))) {
            if ($overwrite) {
                $this->_parts['order'] = $order;
                return $this;
            }
            $this->_parts['order'] = array_merge($order, $this->_parts['order']);
            return $this;
        }

        if (is_string($order)) {
            $order = [$order => ['order' => 'desc']];
        }

        $normalizer = function ($order, $key) {
            // ['field' => 'asc|desc']
            if (is_string($order)) {
                return [$key => ['order' => $order]];
            }
            return [$key => $order];
        };

        $order = collection($order)->map($normalizer)->toList();

        if (!$overwrite) {
            $order = array_merge($this->_parts['order'], $order);
        }

        $this->_parts['order'] = $order;
        return $this;
    }

    public function where($conditions, $overwrite = false)
    {
        if ($this->_parts['preFilter'] === null || $overwrite) {
            $this->_parts['preFilter'] = new BoolFilter;
        }

        if ($conditions instanceof AbstractFilter) {
            $this->_parts['preFilter']->addMust($conditions);
            return $this;
        }

        if (is_callable($conditions)) {
            $conditions = $conditions(new FilterBuilder, $this->_parts['preFilter'], $this);
        }

        if ($conditions === null) {
            return $this;
        }

        if (is_array($conditions)) {
            $conditions = (new FilterBuilder)->parse($conditions);
            array_map([$this->_parts['preFilter'], 'addMust'], $conditions);
            return $this;
        }

        $this->_parts['preFilter']->addMust($conditions);
        return $this;
    }

    public function applyOptions(array $options)
    {
        $this->_options = $options + $this->_options;
    }

    protected function _execute()
    {
        $connection = $this->_repository->connection();
        $name = $this->_repository->name();
        $type = $connection->getIndex()->getType($name);

        $this->_compileQuery();
        return new ResultSet($type->search($this->_elasticQuery), $this);
    }

    protected function _compileQuery()
    {
        if ($this->_parts['fields']) {
            $this->_elasticQuery->setSource($this->_parts['fields']);
        }

        if ($this->_parts['limit']) {
            $this->_elasticQuery->setSize($this->_parts['limit']);
        }

        if ($this->_parts['order']) {
            $this->_elasticQuery->setSort($this->_parts['order']);
        }

        if ($this->_parts['preFilter'] !== null) {
            $filteredQuery = new FilteredQuery();
            $filteredQuery->setFilter($this->_parts['preFilter']);
            $this->_elasticQuery->setQuery($filteredQuery);
        }
    }
}
