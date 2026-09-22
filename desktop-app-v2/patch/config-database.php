<?php
/**
 * SchoolDesk Pro desktop runtime — embedded SQLite database.
 * The whole system state lives in ../data/school.sqlite next to the app.
 */
return [
    'driver'   => 'sqlite',
    'database' => dirname(__DIR__, 2) . '/data/school.sqlite',
];
