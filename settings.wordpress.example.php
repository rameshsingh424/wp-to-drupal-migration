<?php

/**
 * Example WordPress source database connection for Drupal settings.php.
 */

$databases['wordpress']['default'] = [
  'database' => 'wordpress_database_name',
  'username' => 'wordpress_database_user',
  'password' => 'wordpress_database_password',
  'prefix' => '',
  'host' => '127.0.0.1',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
];
