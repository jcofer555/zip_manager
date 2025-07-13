<?php
$historyPath = '/boot/config/plugins/zip_manager/logs/extractor_history.log';

if (!file_exists($historyPath) || !trim(file_get_contents($historyPath))) {
  echo "No extraction history!";
  exit;
}

$lines = array_filter(array_map('trim', file($historyPath)));
echo str_replace('-&gt;', '->', htmlspecialchars(implode("\n", array_slice($lines, -10))));
