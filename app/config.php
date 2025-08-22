<?php
declare(strict_types=1);

return [
  'db' => [
    'dsn'  => 'mysql:host=localhost;dbname=cranwhfb_mybd;charset=utf8mb4',
    'user' => 'cranwhfb_team', 
    'pass' => 'CRAN_root',      
    'options' => [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]
  ],
  'paths' => [
    'base'   => '',
    'public' => '',
    'assets' => '/assets',
  ],
];
