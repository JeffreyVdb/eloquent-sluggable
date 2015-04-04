<?php

/**
 * Test against in-memory SQLite database
 */

return array(
    'driver'   => 'sqlite',
    'database' => ':memory:',
    'prefix'   => '',
    'redis'    => [

        'cluster' => true,

        'default' => ['host' => '192.168.10.10', 'port' => 6379],

    ],
);
