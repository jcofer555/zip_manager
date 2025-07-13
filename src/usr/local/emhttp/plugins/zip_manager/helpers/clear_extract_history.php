<?php
$logFile = '/boot/config/plugins/zip_manager/logs/extractor_history.log';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (file_exists($logFile)) {
    file_put_contents($logFile, ''); // ✅ Clears the file content
    echo "History cleared.";
  } else {
    // Optionally create the log file if it doesn't exist
    file_put_contents($logFile, '');
    echo "History file created and cleared.";
  }
} else {
  http_response_code(405); // Method not allowed
  echo "Only POST requests are allowed.";
}
