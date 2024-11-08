<?php

namespace Tobono\WPDB;

use Exception;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;
use Illuminate\Support\Arr;
use wpdb;

class WPDBProcessor extends MySqlProcessor
{
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $query->getConnection()->insert($sql, $values);

        $id = $query->getConnection()->getPdo()->insert_id ;

        return is_numeric($id) ? (int)$id : $id;
    }
}
