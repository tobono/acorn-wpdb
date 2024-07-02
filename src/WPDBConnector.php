<?php

namespace Tobono\WPDB;

use Exception;
use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Support\Arr;
use wpdb;

class WPDBConnector extends Connector implements ConnectorInterface {
    /**
     * @var array
     */
    protected $options = [];

    /**
     * get or create wpdb database connection
     *
     * @param array $config
     *
     * @return wpdb
     * @throws Exception
     */
    public function connect( array $config ): wpdb {
        if ( $this->isCurrentDatabaseRequested( $config['database'], $config['host'] ) && $this->checkDefaultConnection() ) {
            return $GLOBALS['wpdb'];
        }

        $dsn = '';

        $options = [];

        $connection = $this->createConnection($dsn, $config, $options);

        $connection->set_prefix($config['prefix']);

        $connection->set_charset($config['charset']);

        $connection->field_types = $this->getDefaultWPDBFieldTypes();

        return $connection;
    }

    public function createConnection($dsn, array $config, array $options): wpdb {
        // @TODO we should handle db socket by reading options from config and reverse it for wpdb host @see WPDB::parse_db_host

        list($username, $password, $host, $database) = [
            Arr::get($config, 'username'),
            Arr::get($config, 'password'),
            Arr::get($config, 'host', 'localhost'),
            Arr::get($config, 'database'),
        ];

        try {
            return $this->createWPDBConnection(
                $username, $password, $database, $host
            );
        } catch (Exception $exception) {
            // wpdb already handled the retries. if we want to implement an exception for unexpected causes, we should do it here.
            throw new $exception;
        }
    }

    protected function createWPDBConnection( $username, $password, $database, $host ): wpdb {
        return new wpdb(
            dbuser: $username,
            dbpassword: $password,
            dbname: $database,
            dbhost: $host[]
        );
    }

    protected function checkDefaultConnection(): bool {
        return $GLOBALS['wpdb']->check_connection( false );
    }

    protected function isCurrentDatabaseRequested( $database, $host ): bool {
        return $host === DB_HOST && $database === DB_NAME;
    }

    protected function getDefaultWPDBFieldTypes(): array {
        return [
            'post_author'      => '%d',
            'post_parent'      => '%d',
            'menu_order'       => '%d',
            'term_id'          => '%d',
            'term_group'       => '%d',
            'term_taxonomy_id' => '%d',
            'parent'           => '%d',
            'count'            => '%d',
            'object_id'        => '%d',
            'term_order'       => '%d',
            'ID'               => '%d',
            'comment_ID'       => '%d',
            'comment_post_ID'  => '%d',
            'comment_parent'   => '%d',
            'user_id'          => '%d',
            'link_id'          => '%d',
            'link_owner'       => '%d',
            'link_rating'      => '%d',
            'option_id'        => '%d',
            'blog_id'          => '%d',
            'meta_id'          => '%d',
            'post_id'          => '%d',
            'user_status'      => '%d',
            'umeta_id'         => '%d',
            'comment_karma'    => '%d',
            'comment_count'    => '%d',
            // Multisite:
            'active'           => '%d',
            'cat_id'           => '%d',
            'deleted'          => '%d',
            'lang_id'          => '%d',
            'mature'           => '%d',
            'public'           => '%d',
            'site_id'          => '%d',
            'spam'             => '%d'
        ];
    }
}
