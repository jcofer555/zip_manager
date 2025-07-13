<?php
header('Content-Type: application/json');

$password = $_GET['password'] ?? '';
$passArg = $password ? "-p" . escapeshellarg($password) : "";
$input = $_GET['input'] ?? '';
$output = $_GET['output'] ?? '';

// 🧪 Basic input sanity check
if (!$input || !$output) {
  echo json_encode(['error' => 'Missing input or output']);
  exit;
}

// 🧪 Clean output path
$output = rtrim($output, '/') . '/';

// === Step 1: Run 7z to list archive files ===
exec("/usr/bin/7zzs l $passArg -slt " . escapeshellarg($input), $rawOutput, $code);
if ($code !== 0) {
  echo json_encode(['error' => 'Failed to read archive contents']);
  exit;
}

// === Step 2: Parse archive file paths ===
$archiveFiles = [];
foreach ($rawOutput as $line) {
  if (strpos($line, 'Path = ') === 0) {
    $path = trim(substr($line, 7));
    if ($path && substr($path, -1) !== '/') {
      $archiveFiles[] = $path;
    }
  }
}

// === Step 3: Detect conflicts ===
$conflicts = [];
foreach ($archiveFiles as $relPath) {
  $targetPath = $output . $relPath;
  if (file_exists($targetPath)) {
    $conflicts[] = $relPath;
  }
}

// === Step 6: Final JSON output ===
echo json_encode([
  'conflicts' => $conflicts,
  'count' => count($conflicts)
]);
