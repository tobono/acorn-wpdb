<?php

namespace Tobono\WPDB;

use Illuminate\Support\ServiceProvider as ServiceProviderT;

class WPDBServiceProvider extends ServiceProviderT
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('db.connector.wpdb', function () {
            return new WPDBConnector();
        });

        $this->app['db']->extend('wpdb', function (array $config) {
            $adapter = $this->app['db.connector.wpdb']->connect($config);

            return new WPDBConnection($adapter, $config['database'], $config['prefix'], $config);
        });
    }
}
