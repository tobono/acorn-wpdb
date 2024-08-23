<?php

namespace Tobono\WPDB;

use Closure;
use DateTimeInterface;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Grammar;
use Illuminate\Database\Schema\Grammars\MySqlGrammar as SchemaGrammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar as QueryGrammar;
use Illuminate\Database\Schema\MySqlBuilder;
use InvalidArgumentException;
use LogicException;
use wpdb;

class WPDBConnection extends Connection implements ConnectionInterface {

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
    protected $mysqlEscapeChars = [
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
    public function __construct( $wpdb, $database = '', $tablePrefix = '', array $config = [] ) {
        $this->wpdb = $wpdb;

        parent::__construct( $wpdb, $database, $tablePrefix, $config );
    }

    /**
     * Get the default query grammar instance.
     *
     * @return Grammar|\Illuminate\Database\Query\Grammars\MySqlGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammar());
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
     * @return Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        ($grammar = new SchemaGrammar)->setConnection($this);

        return $this->withTablePrefix($grammar);
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
    public function selectOne( $query, $bindings = [], $useRead = true ) {
        $records = $this->select( $query, $bindings, $useRead );

        return array_shift( $records );
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return array
     */
    public function selectFromWriteConnection( $query, $bindings = [] ) {
        return $this->select( $query, $bindings, false );
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
    public function select( $query, $bindings = [], $useReadPdo = true ) {
        return $this->run( $query, $bindings, function ( $query, $bindings ) use ( $useReadPdo ) {
            if ( $this->pretending() ) {
                return [];
            }

            $query = $this->buildSql( $query, $this->prepareBindings( $bindings ) );

            $statement = $this->getWPDB();

            return $statement->get_results( $query, $this->fetchMode );

        } );
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
    public function cursor( $query, $bindings = [], $useReadPdo = true ) {
        $results = $this->run( $query, $bindings, function ( $query, $bindings ) use ( $useReadPdo ) {
            if ( $this->pretending() ) {
                return [];
            }

            $query = $this->buildSql( $query, $this->prepareBindings( $bindings ) );

            $statement = $this->getWPDB();

            return $statement->get_results( $query, $this->fetchMode );
        } );

        foreach ( $results as $record ) {
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
    public function statement( $query, $bindings = [] ) {
        return $this->run( $query, $bindings, function ( $query, $bindings ) {
            if ( $this->pretending() ) {
                return true;
            }

            $query = $this->buildSql( $query, $this->prepareBindings( $bindings ) );

            $statement = $this->getWPDB();

            return $statement->query( $query );
        } );
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param string $query
     * @param array $bindings
     *
     * @return int
     */
    public function affectingStatement( $query, $bindings = [] ) {
        return $this->run( $query, $bindings, function ( $query, $bindings ) {
            if ( $this->pretending() ) {
                return 0;
            }

            $query = $this->buildSql( $query, $this->prepareBindings( $bindings ) );

            $statement = $this->getWPDB();

            $statement->query( $query );

            return $statement->num_rows;
        } );
    }

    /**
     * Run a raw, unprepared query against the PDO connection.
     *
     * @param string $query
     *
     * @return bool
     */
    public function unprepared( $query ) {
        return $this->run( $query, [], function ( $query ) {
            if ( $this->pretending() ) {
                return true;
            }

            return (bool) $this->getWPDB()->query( $query );
        } );
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     *
     * @return array
     */
    public function prepareBindings( array $bindings ) {
        $grammar = $this->getQueryGrammar();

        foreach ( $bindings as $key => $value ) {
            // We need to transform all instances of DateTimeInterface into the actual
            // date string. Each query grammar maintains its own date string format
            // so we'll just ask the grammar for the format to get from the date.
            if ( $value instanceof DateTimeInterface ) {
                $bindings[ $key ] = $value->format( $grammar->getDateFormat() );
            } elseif ( $value === false ) {
                $bindings[ $key ] = 0;
            }
        }

        return $bindings;
    }

    /**
     * Is Doctrine available?
     *
     * @return bool
     */
    public function isDoctrineAvailable() {
        return false;
    }

    /**
     * Get the current WPDB connection.
     *
     * @return wpdb
     */
    public function getWPDB(): WPDB {
        return $this->wpdb;
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected function getMySqliBindType( $value ) {
        // Check if value is an expression
        if ( is_callable( $value ) ) {
            return '%s';
        }

        return match ( gettype( $value ) ) {
            'double' => '%f',
            'boolean', 'integer' => '%d',
            default => '%s'
        };
    }

    public function escape( $value, $binary = false ) {
        return strtr( $value, $this->mysqlEscapeChars );
    }

    /**
     * @param mixed $value
     * @param string $type
     *
     * @return string
     */
    protected function quote( $value, $type = null ) {
        // Check if value is an expression
        if ( is_callable( $value ) ) {
            return $value( $this );
        }

        if ( ! $type ) {
            $type = gettype( $value );
        }

        switch ( $type ) {

            case 'boolean':
                $value = (int) $value;
                break;

            case 'double':
            case 'integer':
                break;

            case 'string':
                if ( $this->isBinary( $value ) ) {
                    $value = "'" . addslashes( $value ) . "'";
                } else {
                    $value = "'" . $this->escape( $value ) . "'";
                }
                break;

            case 'array':
                $nvalue = [];
                foreach ( $value as $v ) {
                    $nvalue[] = $this->quote( $v );
                }
                $value = implode( ',', $nvalue );
                break;

            case 'NULL':
                $value = 'NULL';
                break;

            case 'object':
                if ( $value instanceof Closure ) {
                    return $value( $this );
                }
                break;

            default:
                throw new InvalidArgumentException( sprintf( 'Not supportted value type of %s.', $type ) );
                break;
        }

        return $value;
    }

    protected function quoteColumn( $str ) {
        return '`' . $str . '`';
    }

    protected function buildSql( $sql, $params = [] ) {
        if ( empty( $params ) || empty( $sql ) ) {
            return $sql;
        }

        $builtSql = $sql;

        if ( $this->isAssoc( $params ) ) {

            // We bind template variable placeholders like ":param1"
            $trans = [];

            foreach ( $params as $key => $value ) {
                $trans[ $key ] = $this->quote( $value );
                //$builtSql = str_replace($key, $replacement, $builtSql);
            }

            return strtr( $builtSql, $trans );
        } else {

            // We bind question mark \"?\" placeholders
            $offset = strpos( $builtSql, '?' );

            foreach ( $params as $i => $param ) {

                if ( $offset === false ) {
                    throw new LogicException( "Param $i has no matching question mark \"?\" placeholder in specified SQL query." );
                }

                $replacement = $this->quote( $param );
                $builtSql    = substr_replace( $builtSql, $replacement, $offset, 1 );
                $offset      = strpos( $builtSql, '?', $offset + strlen( $replacement ) );
            }

            if ( $offset !== false ) {
                throw new LogicException( 'Not enough parameter bound to SQL query' );
            }

        }

        return $builtSql;
    }

    protected function isBinary( $str ): bool {
        return preg_match( '~[^\x20-\x7E\t\r\n]~', $str ) > 0;
    }

    protected function isAssoc( array $arr ): bool {
        $keys = array_keys( $arr );

        return array_keys( $keys ) !== $keys;
    }
}
