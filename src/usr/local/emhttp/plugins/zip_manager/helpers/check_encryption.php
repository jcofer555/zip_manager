<?php
header('Content-Type: application/json');

$input = $_GET['input'] ?? '';
if (!$input || !file_exists($input)) {
  echo json_encode(['error' => 'Missing or invalid input file']);
  exit;
}

// Try testing the archive with a dummy password
exec("/usr/bin/7zzs t -pwrongpassword " . escapeshellarg($input), $output, $code);

$isEncrypted = false;
foreach ($output as $line) {
  if (
    strpos($line, 'Wrong password') !== false ||
    strpos($line, 'Can not open encrypted archive') !== false ||
    strpos($line, 'Errors:') !== false ||
    strpos($line, 'Can\'t open as archive') !== false
  ) {
    $isEncrypted = true;
    break;
  }
}

echo json_encode(['encrypted' => $isEncrypted]);
