<?php
declare(strict_types=1);

return [
  'db' => [
    'dsn'  => 'mysql:host=localhost;port=3306;dbname=mybd_gobierno;charset=utf8mb4',
    'user' => 'root', 
    'pass' => '',      
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
