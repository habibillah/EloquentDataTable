<?php
namespace LiveControl\EloquentDataTable\VersionTransformers;

use Symfony\Component\HttpFoundation\ParameterBag;

class Version110Transformer implements VersionTransformerContract
{
    protected $param;

    public function __construct(ParameterBag $param)
    {
        $this->param = $param;
    }

    public function transform($name)
    {
        return $name; // we use the same as the requested name
    }

    public function getSearchValue()
    {
        $search = $this->param->get('search', []);

        return isset($search['value']) ? $search['value'] : '';
    }

    public function isColumnSearched($columnIndex)
    {
        $columns = $this->param->get('columns', []);
        return (
            isset($columns[$columnIndex])
            &&
            isset($columns[$columnIndex]['search'])
            &&
            isset($columns[$columnIndex]['search']['value'])
            &&
            $columns[$columnIndex]['search']['value'] != ''
        );
    }

    public function getColumnSearchValue($columnIndex)
    {
        $columns = $this->param->get('columns', [$columnIndex => ['search' => ['value' => '']]]);

        return $columns[$columnIndex]['search']['value'];
    }

    public function isOrdered()
    {
        $order = $this->param->get('order', []);
        return (isset($order[0]));
    }

    public function getOrderedColumns()
    {
        $columns = [];
        $orders = $this->param->get('order', []);

        foreach ($orders as $i => $order) {
            $columns[(int) $order['column']] = ($order['dir'] == 'asc' ? 'asc' : 'desc');
        }
        return $columns;
    }
}
