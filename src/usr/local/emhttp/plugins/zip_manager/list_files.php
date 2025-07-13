<?php
header('Content-Type: application/json');

$dir = rtrim($_GET['dir'] ?? '/mnt/user/', '/');
if (!is_dir($dir)) {
    echo json_encode(["error" => "Invalid path"]);
    exit;
}

$items = [];
foreach (scandir($dir) as $item) {
    if ($item === '.' || $item === '..') continue;
    $fullPath = "$dir/$item";
    $items[] = is_dir($fullPath) ? "$item/" : $item;
}

echo json_encode($items);
?>
