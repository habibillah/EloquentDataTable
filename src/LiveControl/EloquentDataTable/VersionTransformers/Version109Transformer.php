<?php

namespace LiveControl\EloquentDataTable\VersionTransformers;

use Symfony\Component\HttpFoundation\ParameterBag;

class Version109Transformer implements VersionTransformerContract
{
    protected $param;

    protected static $translate = [
        'draw'              => 'sEcho',
        'recordsTotal'      => 'iTotalRecords',
        'recordsFiltered'   => 'iTotalDisplayRecords',
        'data'              => 'aaData',
        'start'             => 'iDisplayStart',
        'length'            => 'iDisplayLength',

    ];

    public function __construct(ParameterBag $param)
    {
        $this->param = $param;
    }

    public function transform($name)
    {
        return (isset(static::$translate[$name]) ? static::$translate[$name] : $name);
    }

    public function getSearchValue()
    {
        return $this->param->get('sSearch', '');
    }

    public function isColumnSearched($i)
    {
        return ($this->param->get('bSearchable_' . $i) && $this->param->get('bSearchable_' . $i) == 'true' && $this->param->get('sSearch_' . $i) != '');
    }

    public function getColumnSearchValue($i)
    {
        return $this->param->get('sSearch_' . $i);
    }

    public function isOrdered()
    {
        return (bool) $this->param->get('iSortCol_0');
    }

    public function getOrderedColumns()
    {
        $columns    = [];
        $sortingCols= (int) $this->param->get('iSortingCols');

        for ($i = 0; $i < $sortingCols; $i ++) {
            $sortCol = (int) $this->param->get('iSortCol_' . $i);

            if ($this->param->get('bSortable_' . $sortCol) == 'true') {
                $columns[$sortCol] = $this->param->get('sSortDir_' . $i) == 'asc' ? 'asc' : 'desc';
            }
        }

        return $columns;
    }
}
