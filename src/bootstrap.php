<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = dirname(__DIR__);
    $config = require $root . '/config.php';
    $local = $root . '/config.local.php';
    if (is_file($local)) {
        $config = array_replace_recursive($config, require $local);
    }

    return $config;
}

function app_db(): mysqli
{
    $c = app_config()['db'];
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $db = new mysqli($c['host'], $c['user'], $c['pass'], '', (int) $c['port']);
    $db->set_charset('utf8mb4');

    $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $c['name']);
    if ($name === '') {
        throw new RuntimeException('Некоректна назва бази.');
    }
    $db->query("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($name);

    $sql = file_get_contents(dirname(__DIR__) . '/sql/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (preg_match('/^(CREATE DATABASE|USE)\b/i', $statement)) {
            continue;
        }
        $db->query($statement);
    }

    return $db;
}
