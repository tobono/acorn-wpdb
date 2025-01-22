<?php

namespace Tobono\WPDB;

class WPDBPdoCompat {
    protected $wpdb;
    protected $attributes = [];

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
        $this->attributes[\PDO::ATTR_DRIVER_NAME] = 'mysql';
        $this->attributes[\PDO::ATTR_SERVER_VERSION] = $wpdb->db_version();
    }

    public function getAttribute($attribute)
    {
        return $this->attributes[$attribute] ?? null;
    }

    public function setAttribute($attribute, $value)
    {
        $this->attributes[$attribute] = $value;
    }

    public function beginTransaction()
    {
        return $this->wpdb->query('START TRANSACTION');
    }

    public function commit()
    {
        return $this->wpdb->query('COMMIT');
    }

    public function rollBack()
    {
        return $this->wpdb->query('ROLLBACK');
    }

    public function inTransaction()
    {
        // WPDB doesn't track transaction state, so we'll have to assume it's not in a transaction
        return false;
    }

    public function __get($name)
    {
        return $this->wpdb->$name;
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->wpdb, $name], $arguments);
    }
}
