<?php

namespace Tobono\WPDB;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider as ServiceProviderT;

class WPDBServiceProvider extends ServiceProviderT
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->bind('db.connector.wpdb', WPDBConnector::class);

        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('wpdb', function (array $config, string $name): WPDBConnection {
                $config['name'] = $name;

                return new WPDBConnection(
                    $this->app['db.connector.wpdb']->connect($config),
                    $config['database'],
                    $config['prefix'],
                    $config
                );
            });
        });
    }
}
