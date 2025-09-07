<?php
/************************************************************
 * CRAN – Configuración común
 ************************************************************/
return [
  // Oracle local (PC Monitoreo): usuario con acceso a V$... local
  'ora' => [
    'username' => 'cranmon',
    'password' => 'cranmon123',
    'dsn'      => '//localhost:1521/XEPDB1',
    'charset'  => 'AL32UTF8',
  ],
  // MySQL local (phpMyAdmin) para persistir monitores y alertas
  'mysql' => [
    'dsn'      => 'mysql:host=127.0.0.1;dbname=mybd_gobierno;charset=utf8mb4',
    'username' => 'root',
    'password' => '',
  ],
  'max_points' => 600,   // puntos en gráfico por tarjeta
];
