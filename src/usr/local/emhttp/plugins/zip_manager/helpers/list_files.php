<?php
$dir = $_GET['dir'] ?? '/mnt/';

if (!is_dir($dir)) {
    echo json_encode(['error' => "Invalid path"]);
    exit;
}

$items = scandir($dir);
$results = [];

foreach ($items as $item) {
    if ($item === '.' || $item === '..') continue;

    $fullPath = $dir . $item;
    $isDir = is_dir($fullPath);
    $name = $item . ($isDir ? '/' : '');

    $results[] = [
        'name' => $name,
        'size' => $isDir ? 0 : filesize($fullPath)
    ];
}

header('Content-Type: application/json');
echo json_encode($results);
