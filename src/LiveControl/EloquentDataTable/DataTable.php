<?php
namespace LiveControl\EloquentDataTable;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Database\Query\Expression as raw;
use Symfony\Component\HttpFoundation\ParameterBag;
use LiveControl\EloquentDataTable\VersionTransformers\Version110Transformer;
use LiveControl\EloquentDataTable\VersionTransformers\VersionTransformerContract;

class DataTable
{
    protected $str;
    protected $param;
    protected $builder;
    protected $columns;
    protected $connection;
    protected $rowFormatter;

    /**
     * @var VersionTransformerContract
     */
    protected $versionTransformer;

    protected $rawColumns;
    protected $columnNames;

    protected $total = 0;
    protected $filtered = 0;

    protected $rows = [];

    /**
     * @param Builder|Model $builder
     * @param array $columns
     * @param null|callable $rowFormatter
     * @throws Exception
     */
    public function __construct(Connection $connection, $builder, ParameterBag $param, Str $str)
    {
        $this->str          = $str;
        $this->param        = $param;
        $this->connection   = $connection;
        $this->setBuilder($builder);
    }

    /**
     * @param Builder|Model $builder
     * @return $this
     * @throws Exception
     */
    public function setBuilder($builder)
    {
        if (!($builder instanceof Builder || $builder instanceof Model)) {
            throw new Exception('$builder variable is not an instance of Builder or Model.');
        }

        $this->builder = $builder;
        return $this;
    }

    /**
     * @param mixed $columns
     * @return $this
     */
    public function setColumns($columns)
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * @param callable $function
     * @return $this
     */
    public function setRowFormatter($function)
    {
        $this->rowFormatter = $function;
        return $this;
    }

    /**
     * @param VersionTransformerContract $versionTransformer
     * @return $this
     */
    public function setVersionTransformer(VersionTransformerContract $versionTransformer)
    {
        $this->versionTransformer = $versionTransformer;
        return $this;
    }

    /**
     * Make the datatable response.
     * @return array
     * @throws Exception
     */
    public function make()
    {
        $this->total = $this->builder->count();

        $this->rawColumns = $this->getRawColumns($this->columns);
        $this->columnNames = $this->getColumnNames();

        if ($this->versionTransformer === null) {
            $this->versionTransformer = new Version110Transformer($this->param);
        }

        $this->addSelect();
        $this->addFilters();

        $this->filtered = $this->builder->count();

        $this->addOrderBy();
        $this->addLimits();

        $this->rows = $this->builder->get();

        $rows = [];
        foreach ($this->rows as $row) {
            $rows[] = $this->formatRow($row);
        }

        $drawKey = $this->versionTransformer->transform('draw');

        return [
            $this->versionTransformer->transform('draw') => $this->param->get($drawKey, 0),
            $this->versionTransformer->transform('recordsTotal') => $this->total,
            $this->versionTransformer->transform('recordsFiltered') => $this->filtered,
            $this->versionTransformer->transform('data') => $rows
        ];
    }

    /**
     * @param $data
     * @return array|mixed
     */
    protected function formatRow($data)
    {
        // if we have a custom format row function we trigger it instead of the default handling.
        if ($this->rowFormatter !== null) {
            $function = $this->rowFormatter;

            return call_user_func($function, $data);
        }

        $result = [];
        foreach ($this->columnNames as $column) {
            $result[] = $data[$column];
        }
        $data = $result;

        $data = $this->formatRowIndexes($data);

        return $data;
    }

    /**
     * @param $data
     * @return mixed
     */
    protected function formatRowIndexes($data)
    {
        if (isset($data['id'])) {
            $data[$this->versionTransformer->transform('DT_RowId')] = $data['id'];
        }
        return $data;
    }

    /**
     * @return array
     */
    protected function getColumnNames()
    {
        $names = [];
        foreach ($this->columns as $index => $column) {
            if ($column instanceof ExpressionWithName) {
                $names[] = $column->getName();
                continue;
            }

            if (is_string($column) && strstr($column, '.')) {
                $column = explode('.', $column);
            }

            $names[] = (is_array($column) ? $this->arrayToCamelcase($column) : $column);
        }
        return $names;
    }

    /**
     * @param $columns
     * @return array
     */
    protected function getRawColumns($columns)
    {
        $rawColumns = [];
        foreach ($columns as $column) {
            $rawColumns[] = $this->getRawColumnQuery($column);
        }
        return $rawColumns;
    }

    /**
     * @param $column
     * @return raw|string
     */
    protected function getRawColumnQuery($column)
    {
        if ($column instanceof ExpressionWithName) {
            return $column->getExpression();
        }

        if (is_array($column)) {
            if ($this->getDatabaseDriver() == 'sqlite') {
                return '(' . implode(' || " " || ', $this->getRawColumns($column)) . ')';
            }
            return 'CONCAT(' . implode(', " ", ', $this->getRawColumns($column)) . ')';
        }

        return $this->connection->getQueryGrammar()->wrap($column);
    }

    /**
     * @return string
     */
    protected function getDatabaseDriver()
    {
        return $this->connection->getDriverName();
    }

    /**
     *
     */
    protected function addSelect()
    {
        $rawSelect = [];
        foreach ($this->columns as $index => $column) {
            if (isset($this->rawColumns[$index])) {
                $rawSelect[] = $this->rawColumns[$index] . ' as ' . $this->connection->getQueryGrammar()->wrap($this->columnNames[$index]);
            }
        }
        $this->builder = $this->builder->select(new raw(implode(', ', $rawSelect)));
    }

    /**
     * @param $array
     * @param bool|false $inForeach
     * @return array|string
     */
    protected function arrayToCamelcase($array, $inForeach = false)
    {
        $result = [];
        foreach ($array as $value) {
            if (is_array($value)) {
                $result += $this->arrayToCamelcase($value, true);
            }
            $value = explode('.', $value);
            $value = end($value);
            $result[] = $value;
        }

        return $inForeach ? $result : $this->str-camel(implode('_', $result));
    }

    /**
     * Add the filters based on the search value given.
     * @return $this
     */
    protected function addFilters()
    {
        $search = $this->versionTransformer->getSearchValue();
        if ($search != '') {
            $this->addAllFilter($search);
        }
        $this->addColumnFilters();
        return $this;
    }

    /**
     * Searches in all the columns.
     * @param $search
     */
    protected function addAllFilter($search)
    {
        $this->builder = $this->builder->where(
            function ($query) use ($search) {
                foreach ($this->columns as $column) {
                    $query->orWhere(
                        new raw($this->getRawColumnQuery($column)),
                        'like',
                        '%' . $search . '%'
                    );
                }
            }
        );
    }

    /**
     * Add column specific filters.
     */
    protected function addColumnFilters()
    {
        foreach ($this->columns as $i => $column) {
            if ($this->versionTransformer->isColumnSearched($i)) {
                $this->builder->where(
                    new raw($this->getRawColumnQuery($column)),
                    'like',
                    '%' . $this->versionTransformer->getColumnSearchValue($i) . '%'
                );
            }
        }
    }

    /**
     * Depending on the sorted column this will add orderBy to the builder.
     */
    protected function addOrderBy()
    {
        if ($this->versionTransformer->isOrdered()) {
            foreach ($this->versionTransformer->getOrderedColumns() as $index => $direction) {
                if (isset($this->columnNames[$index])) {
                    $this->builder->orderBy(
                        $this->columnNames[$index],
                        $direction
                    );
                }
            }
        }
    }

    /**
     * Adds the pagination limits to the builder
     */
    protected function addLimits()
    {
        $startField  = $this->versionTransformer->transform('start');
        $lengthField = $this->versionTransformer->transform('length');

        $start  = $this->param->get($startField, 0);
        $length = $this->param->get($lengthField, 10);

        if ($length != '-1') {
            $this->builder
                 ->skip((int) $start)
                 ->take((int) $length);
        }
    }
}
