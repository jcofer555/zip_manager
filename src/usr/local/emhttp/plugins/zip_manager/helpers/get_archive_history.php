<?php
$historyPath = '/boot/config/plugins/zip_manager/logs/archiver_history.log';

if (!file_exists($historyPath) || !trim(file_get_contents($historyPath))) {
  echo "No archiving history!";
  exit;
}

$lines = array_filter(array_map('trim', file($historyPath)));
echo str_replace('-&gt;', '->', htmlspecialchars(implode("\n", array_slice($lines, -20))));
