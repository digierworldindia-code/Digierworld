<?php
// QMS configuration. install.php creates config/config.php from your answers;
// copy this file to config.php only if you configure QMS by hand.
// Keep config.php private and never upload it to Git.
return [
    'base_url'                => 'https://qms.example.com/',   // address users type, with trailing slash
    'environment'             => 'production',                 // 'development' shows error details – never on a live server
    'force_https'             => true,                         // redirect http:// to https://
    'db'                      => [
        'host'   => 'localhost',
        'port'   => 3306,
        'socket' => '',                                         // e.g. /var/run/mysqld/mysqld.sock (optional)
        'name'   => 'qms',
        'user'   => 'qms_user',
        'pass'   => '',
    ],
    'trusted_proxies'         => [],                            // IPs of a load balancer / reverse proxy, e.g. ['10.0.0.5']
    'google_credentials_file' => '',                            // path to the Google service-account key, OUTSIDE public folders
];
