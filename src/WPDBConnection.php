<?php

namespace Tobono\WPDB;

use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Concerns\ManagesTransactions;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Illuminate\Database\Schema\MySqlBuilder;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use wpdb;

class WPDBConnection extends Connection implements ConnectionInterface {

    use ManagesTransactions;

    protected WPDBPdoCompat $pdoCompat;

    /**
     * The active WPDB connection.
     *
     * @var wpdb
     */
    protected $wpdb;

    protected $fetchMode = ARRAY_A;

    /**
     * @var array
     */
    protected const MYSQL_ESCAPE_CHARS = [
        "\x00" => "\\0",
        "\r"   => "\\r",
        "\n"   => "\\n",
        "\t"   => "\\t",
        "'"    => "\'",
        '"'    => '\"',
        "\\"   => "\\\\",
        "\0"   => '\\0',
    ];

    /**
     * get wpdb connection
     *
     * @param wpdb|Closure $wpdb
     * @param string $database
     * @param string $tablePrefix
     * @param array $config
     *
     * @return void
     */
    public function __construct($wpdb, $database = '', $tablePrefix = '', array $config = [])
    {
        $this->wpdb = $wpdb;
        parent::__construct($wpdb, $database, $tablePrefix, $config);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return Grammar|\Illuminate\Database\Query\Grammars\MySqlGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    protected function getDefaultPostProcessor()
    {
        return new WPDBProcessor();
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\MySqlBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }
        return new MySqlBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\MySqlGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Determine if the connected database is a MariaDB database.
     *
     * @return bool
     */
    public function isMaria()
    {
        return true;
    }

    /**
     * Run a select statement and return a single result.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useRead
     *
     * @return mixed
     */
    public function selectOne($query, $bindings = [], $useRead = true)
    {
        $records = $this->select($query, $bindings, $useRead);
        return array_shift($records);
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return array
     */
    public function selectFromWriteConnection($query, $bindings = [])
    {
        return $this->select($query, $bindings, false);
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     *
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) use ($useReadPdo) {
            if ($this->pretending()) {
                return [];
            }

            $query = $this->buildSql($query, $this->prepareBindings($bindings));
            return $this->getWPDB()->get_results($query, $this->fetchMode);
        });
    }

    /**
     * Run a select statement against the database and returns a generator.
     *
     * @param string $query
     * @param array $bindings
     * @param bool $useReadPdo
     *
     * @return Generator
     */
    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $results = $this->select($query, $bindings, $useReadPdo);

        foreach ($results as $record) {
            yield $record;
        }
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $query = $this->buildSql($query, $this->prepareBindings($bindings));
            return $this->getWPDB()->query($query);
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $query = $this->buildSql($query, $this->prepareBindings($bindings));
            $wpdb = $this->getWPDB();
            $wpdb->query($query);

            return $wpdb->rows_affected;
        });
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     *
     * @return bool
     */
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            return (bool) $this->getWPDB()->query($query);
        });
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     *
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif ($value === false) {
                $bindings[$key] = 0;
            }
        }

        return $bindings;
    }

    /**
     * Is Doctrine available?
     *
     * @return bool
     */
    public function isDoctrineAvailable()
    {
        return false;
    }

    /**
     * Get the current WPDB connection.
     *
     * @return wpdb
     * @throws RuntimeException
     */
    public function getWPDB(): wpdb
    {
        if ($this->wpdb instanceof Closure) {
            $this->wpdb = call_user_func($this->wpdb);
        }

        if ( !( $this->wpdb instanceof wpdb) ) {
            throw new RuntimeException('Invalid WPDB connection');
        }

        return $this->wpdb;
    }

    /**
     * Get the current PDO connection.
     *
     * @return WPDBPdoCompat
     */
    public function getPdo(): WPDBPdoCompat
    {
        if (!isset($this->pdoCompat)) {
            $this->pdoCompat = new WPDBPdoCompat($this->getWPDB());
        }
        if ($this->transactions > 0 && !$this->pdoCompat->inTransaction()) {
            // Something went wrong with transaction state
            $this->transactions = 0;
            throw new LogicException('Transaction state mismatch detected');
        }
        return $this->pdoCompat;
    }

    /**
     * @param mixed $value
     * @return string
     * @throws InvalidArgumentException
     */
    protected function getMySqliBindType($value): string
    {
        if (is_resource($value)) {
            throw new InvalidArgumentException('Resources cannot be bound as parameters');
        }

        if (is_callable($value)) {
            return '%s';
        }

        return match (gettype($value)) {
            'double' => '%f',
            'boolean', 'integer' => '%d',
            'string', 'NULL' => '%s',
            default => throw new InvalidArgumentException('Unsupported parameter type: ' . gettype($value))
        };
    }

    /**
     * @param mixed $value
     * @param bool $binary
     * @return string
     * @throws InvalidArgumentException
     */
    public function escape($value, $binary = false): string
    {
        if (!is_scalar($value) && !is_null($value)) {
            throw new InvalidArgumentException('Cannot escape non-scalar values');
        }

        if ($value === null) {
            return 'NULL';
        }

        $value = (string)$value;
        return $binary ? addslashes($value) : strtr($value, self::MYSQL_ESCAPE_CHARS);
    }

    /**
     * @param mixed $value
     * @param string|null $type
     *
     * @return string
     */
    protected function quote($value, $type = null)
    {
        // Handle null first
        if ($value === null) {
            return 'NULL';
        }

        if (is_callable($value)) {
            return $value($this);
        }

        $type ??= gettype($value);

        switch ($type) {
            case 'boolean':
                return (string)(int)$value;

            case 'double':
            case 'integer':
                return (string)$value;

            case 'string':
                return $this->isBinary($value)
                    ? "'" . addslashes($value) . "'"
                    : "'" . $this->escape($value) . "'";

            case 'array':
                return implode(',', array_map(fn($v) => $this->quote($v), $value));

            case null:
            case 'NULL':
                return 'NULL';

            case 'object':
                if ($value instanceof Closure) {
                    return $value($this);
                }
            // Intentional fallthrough

            default:
                throw new InvalidArgumentException(
                    sprintf('Not supported value type of %s.', $type)
                );
        }
    }

    protected function quoteColumn($str)
    {
        return "`{$str}`";
    }

    protected function buildSql($sql, $params = [])
    {
        if (empty($params) || empty($sql)) {
            return $sql;
        }

        return $this->isAssoc($params)
            ? $this->buildNamedParameterSql($sql, $params)
            : $this->buildPositionalParameterSql($sql, $params);
    }

    /**
     * Build SQL with named parameters
     *
     * @param string $sql
     * @param array $params
     * @return string
     * @throws InvalidArgumentException
     */
    protected function buildNamedParameterSql($sql, $params)
    {
        if (!is_string($sql)) {
            throw new InvalidArgumentException('SQL query must be a string');
        }
        if (!is_array($params)) {
            throw new InvalidArgumentException('Parameters must be an array');
        }

        $translations = [];
        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Parameter keys must be strings');
            }
            $translations[$key] = $this->quote($value);
        }
        return strtr($sql, $translations);
    }

    /**
     * Build SQL with positional parameters
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    protected function buildPositionalParameterSql($sql, $params)
    {
        $offset = strpos($sql, '?');
        foreach ($params as $i => $param) {
            if ($offset === false) {
                throw new LogicException(
                    "Param {$i} has no matching question mark \"?\" placeholder in specified SQL query."
                );
            }

            $replacement = $this->quote($param);
            $sql = substr_replace($sql, $replacement, $offset, 1);
            $offset = strpos($sql, '?', $offset + strlen($replacement));
        }

        if ($offset !== false) {
            throw new LogicException('Not enough parameters bound to SQL query');
        }

        return $sql;
    }

    protected function isBinary($str): bool
    {
        return preg_match('~[^\x20-\x7E\t\r\n]~', $str) > 0;
    }

    protected function isAssoc(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
