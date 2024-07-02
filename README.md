# Acorn WPDB

Acorn WPDB is a laravel eloquent driver for [Acorn](https://github.com/roots/acorn) or [Sage 10](https://github.com/roots/sage) based projects.

## Installation

Install via composer:

```bash
composer require tobono/acorn-wpdb
```

## Usage

You should configure your database connection to use the ```wpdb``` driver.

**Example**
```php
//...
  'connections' => [
        'wpdb' => [
            'driver' => 'wpdb',
            'host' => DB_HOST,
            'database' => DB_NAME,
            'username' => DB_USER,
            'password' => DB_PASSWORD,
            'prefix' => $GLOBALS['wpdb']->prefix,
        ],
    ]
//...
```
