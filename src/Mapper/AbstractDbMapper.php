<?php
/**
 * @copyright: DotKernel
 * @library: dotkernel/dot-ems
 * @author: n3vrax
 * Date: 11/17/2016
 * Time: 7:56 PM
 */

namespace Dot\Ems\Mapper;

use Dot\Ems\Entity\SearchableColumnsProvider;
use Dot\Ems\Entity\SortableColumnsProvider;
use Dot\Ems\Exception\InvalidArgumentException;
use Dot\Ems\Exception\RuntimeException;
use Dot\Ems\ObjectPropertyTrait;
use Dot\Ems\Paginator\Adapter\DbSelect;
use Zend\Db\Adapter\Adapter;
use Zend\Db\Metadata\MetadataInterface;
use Zend\Db\Metadata\Source\Factory;
use Zend\Db\ResultSet\HydratingResultSet;
use Zend\Db\Sql\Expression;
use Zend\Db\Sql\Select;
use Zend\Db\Sql\Where;
use Zend\Db\TableGateway\Feature\FeatureSet;
use Zend\Db\TableGateway\TableGateway;
use Zend\Hydrator\ClassMethods;
use Zend\Hydrator\HydratorInterface;
use Zend\Paginator\AdapterPluginManager;
use Zend\Paginator\Paginator;
use Zend\Stdlib\ArrayUtils;

/**
 * Class AbstractDbMapper
 * @package Dot\Ems\Mapper
 */
abstract class AbstractDbMapper implements MapperInterface
{
    use ObjectPropertyTrait;

    /** @var  Adapter */
    protected $adapter;

    /** @var  TableGateway */
    protected $tableGateway;

    /** @var  object */
    protected $prototype;

    /** @var  HydratorInterface */
    protected $hydrator;

    /** @var  string */
    protected $identifier = 'id';

    /** @var  string */
    protected $paginatorAdapterName = DbSelect::class;

    /** @var  AdapterPluginManager */
    protected $paginatorAdapterManager;

    /** @var  MetadataInterface */
    protected $metadata;

    /** @var  Select */
    protected $currentSelect;

    /**
     * AbstractDbMapper constructor.
     * @param $table
     * @param Adapter $adapter
     * @param $prototype
     * @param HydratorInterface|null $hydrator
     * @param FeatureSet|null $features
     */
    public function __construct(
        $table,
        Adapter $adapter,
        $prototype,
        HydratorInterface $hydrator = null,
        FeatureSet $features = null)
    {
        $this->adapter = $adapter;
        $this->prototype = $prototype;
        $this->hydrator = $hydrator;

        $this->metadata = Factory::createSourceFromAdapter($adapter);

        if (!is_object($this->prototype)) {
            throw new InvalidArgumentException('Entity prototype must be an object');
        }

        if (!$this->hydrator instanceof HydratorInterface) {
            $this->hydrator = new ClassMethods(false);
        }

        $resultSetPrototype = new HydratingResultSet($this->hydrator, $this->prototype);
        $this->tableGateway = new TableGateway($table, $adapter, $features, $resultSetPrototype);
    }

    /**
     * @return int
     */
    public function lastInsertValue()
    {
        return $this->tableGateway->getLastInsertValue();
    }

    /**
     * Begin transaction
     */
    public function beginTransaction()
    {
        $connection = $this->tableGateway->getAdapter()->getDriver()->getConnection();
        $connection->beginTransaction();
    }

    /**
     * Commit transaction
     */
    public function commit()
    {
        $connection = $this->tableGateway->getAdapter()->getDriver()->getConnection();
        $connection->commit();
    }

    /**
     * Rollback transaction
     */
    public function rollback()
    {
        $connection = $this->tableGateway->getAdapter()->getDriver()->getConnection();
        $connection->rollback();
    }

    /**
     * @param $where
     * @return null|object
     */
    public function fetch($where)
    {
        $entity = null;
        /** @var HydratingResultSet $resultSet */
        $resultSet = $this->tableGateway->select($where);
        if ($resultSet && $resultSet->valid()) {
            $entity = $resultSet->current();
        }

        return $entity;
    }

    /**
     * @param array $where
     * @param array $filters
     * @param bool $paginated
     * @return array|null|Paginator
     */
    public function fetchAll($where = [], $filters = [], $paginated = false)
    {
        $entities = null;

        $select = $this->tableGateway->getSql()->select();
        $this->currentSelect = $select;

        if (!empty($where)) {
            $select->where($where);
        }

        $select = $this->applyFilters($select, $filters);

        if (!$paginated) {
            /** @var HydratingResultSet $resultSet */
            $resultSet = $this->tableGateway->selectWith($select);
            if ($resultSet && $resultSet->valid()) {
                $entities = ArrayUtils::iteratorToArray($resultSet, false);
            }

            return $entities;
        } else {
            $paginatorAdapter = $this->getPaginatorAdapter();
            return new Paginator($paginatorAdapter);
        }
    }

    /**
     * @param $entity
     * @return int
     */
    public function create($entity)
    {
        $data = $this->entityToArray($entity, false);

        $this->tableGateway->insert($data);
        $this->setProperty($entity, $this->getIdentifierName(), $this->lastInsertValue());

        return $this->lastInsertValue();
    }

    /**
     * @param $entity
     * @return void
     */
    public function update($entity)
    {
        $data = $this->entityToArray($entity);

        if(!isset($data[$this->identifier])) {
            throw new InvalidArgumentException('Cannot update entity without and identifier');
        }

        $id = $data[$this->identifier];
        unset($data[$this->identifier]);

        $this->tableGateway->update($data, [$this->identifier => $id]);
    }

    /**
     * @param $entity
     * @return void
     */
    public function delete($entity)
    {
        if(!is_object($entity)) {
            throw new InvalidArgumentException('Entity must be an object');
        }

        $id = $this->getProperty($entity, $this->getIdentifierName());
        if(!$id) {
            throw new InvalidArgumentException('Cannot delete an entity without an identifier');
        }
        $this->tableGateway->delete([$this->getIdentifierName() => $id]);
    }

    /**
     * @return object
     */
    public function getPrototype()
    {
        return $this->prototype;
    }

    /**
     * @return ClassMethods|HydratorInterface
     */
    public function getHydrator()
    {
        if (!$this->hydrator instanceof HydratorInterface) {
            $this->hydrator = new ClassMethods(false);
        }
        return $this->hydrator;
    }

    /**
     * @return string
     */
    public function getIdentifierName()
    {
        return $this->identifier;
    }

    /**
     * @param $name
     * @return $this
     */
    public function setIdentifierName($name)
    {
        $this->identifier = $name;
        return $this;
    }

    /**
     * @return TableGateway
     */
    public function getTableGateway()
    {
        return $this->tableGateway;
    }

    /**
     * @param TableGateway $tableGateway
     * @return AbstractDbMapper
     */
    public function setTableGateway($tableGateway)
    {
        $this->tableGateway = $tableGateway;
        return $this;
    }

    /**
     * @return string
     */
    public function getPaginatorAdapterName()
    {
        if(!$this->paginatorAdapterName) {
            $this->paginatorAdapterName = DbSelect::class;
        }
        return $this->paginatorAdapterName;
    }

    /**
     * @param string $paginatorAdapterName
     * @return AbstractDbMapper
     */
    public function setPaginatorAdapterName($paginatorAdapterName)
    {
        $this->paginatorAdapterName = $paginatorAdapterName;
        return $this;
    }

    /**
     * @return mixed
     */
    protected function getPaginatorAdapter()
    {
        $resultSetPrototype = $this->tableGateway->getResultSetPrototype();
        $paginatorAdapter = $this->paginatorAdapterManager->get(
            $this->getPaginatorAdapterName(),
            [$this->currentSelect, $this->adapter, $resultSetPrototype]);

        if(!is_a($paginatorAdapter, $this->getPaginatorAdapterName())
            && !is_subclass_of($paginatorAdapter, $this->getPaginatorAdapterName())) {
            throw new RuntimeException('Paginator adapter for a db mapper must be an instance of '
                . $this->getPaginatorAdapterName() . ' or derivative');
        }

        return $paginatorAdapter;
    }

    /**
     * @return AdapterPluginManager
     */
    public function getPaginatorAdapterManager()
    {
        return $this->paginatorAdapterManager;
    }

    /**
     * @param AdapterPluginManager $paginatorAdapterManager
     * @return AbstractDbMapper
     */
    public function setPaginatorAdapterManager(AdapterPluginManager $paginatorAdapterManager)
    {
        $this->paginatorAdapterManager = $paginatorAdapterManager;
        return $this;
    }

    /**
     * @param $entity
     * @param bool $removeNulls
     * @return array
     */
    protected function entityToArray($entity, $removeNulls = true)
    {
        if(!is_object($entity)) {
            throw new InvalidArgumentException('Entity must be and object');
        }

        $data = $this->hydrator->extract($entity);
        if($removeNulls) {
            $data = array_filter($data);
        }

        return $data;
    }

    /**
     * @param Select $select
     * @param array $filters
     * @return Select
     */
    protected function applyFilters(Select $select, $filters = [])
    {
        if(empty($filters)) {
            return $select;
        }

        $select = $this->applySortFilter($select, $filters);
        $select = $this->applySearchFilters($select, $filters);

        return $select;

    }

    protected function applySearchFilters(Select $select, $filters = [])
    {
        $searchableColumns = [];

        $columns = [];
        $prototype = $this->getPrototype();
        if($prototype instanceof SearchableColumnsProvider) {
            $columns = $prototype->searchableColumns();
        }
        $tableColumns = $this->metadata->getColumnNames($this->tableGateway->getTable());
        foreach ($columns as $column) {
            if(in_array($column, $tableColumns)) {
                $searchableColumns[] = $column;
            }
        }

        if(!empty($searchableColumns)) {
            $search = isset($filters['search']) ? $filters['search'] : '';
            if(!empty($search)) {
                $select->where(function(Where $where) use ($search, $searchableColumns) {
                    $predicate = $where->nest();
                    foreach ($searchableColumns as $column) {
                        $predicate->like($column, '%' . $search . '%')->or;
                    }
                    $predicate->unnest();
                    $where->predicate($predicate);
                });
            }
        }

    }

    protected function applySortFilter(Select $select, $filters = [])
    {
        //sorting options
        $sort = isset($filters['sort']) ? $filters['sort'] : '';
        $order = isset($filters['order']) ? strtoupper($filters['order']) : 'ASC';

        //make sure order param is just the allowed ones
        if(!in_array($order, ['ASC', 'DESC'])) {
            $order = 'ASC';
        }

        $sortableColumns = [];
        $prototype = $this->getPrototype();
        if($prototype instanceof SortableColumnsProvider) {
            $sortableColumns = $prototype->sortableColumns();
        }

        if(!empty($sort) && in_array($sort, $this->metadata->getColumnNames($this->tableGateway->getTable()))
            && in_array($sort, $sortableColumns)) {
            $column = $this->metadata->getColumn($sort, $this->tableGateway->getTable());
            if($column->getDataType() == 'ENUM' || $column->getDataType() == 'SET') {
                $select->order(new Expression('CAST(' . $this->adapter->getPlatform()->quoteIdentifier($sort)
                    . ' as CHAR) ' . $order));
            }
            else {
                $select->order([$sort => $order]);
            }
        }

        return $select;
    }

}