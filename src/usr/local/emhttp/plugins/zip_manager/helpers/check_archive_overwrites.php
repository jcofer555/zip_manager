<?php
header('Content-Type: application/json');

// 🧠 Retrieve query parameters
$inputRaw = $_GET['input'] ?? '';
$output   = $_GET['output'] ?? '';
$format   = $_GET['format'] ?? '7z';
$name     = $_GET['name']   ?? 'archive';

// 🧼 Optional: sanitize archive name
$name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);

// ✅ Validate output only (multi-input irrelevant for overwrite)
if (!$output || !is_dir($output)) {
  echo json_encode(['error' => '❌ Output directory not valid']);
  exit;
}

// 🧪 Build archive filename by format
$archiveName = match ($format) {
  'zip'    => $name . '.zip',
  'tar'    => $name . '.tar',
  'tar.gz' => $name . '.tar.gz',
  'zstd' => $name . '.tar.zst',
  'rar'    => $name . '.rar',
  default  => $name . '.7z',
};

$archivePath = rtrim($output, '/') . '/' . $archiveName;
$exists      = file_exists($archivePath);

// 📢 Response
echo json_encode([
  'exists'  => $exists,
  'archive' => $archivePath,
  'message' => $exists
    ? '⚠️ Archive already exists at the target location.'
    : '✅ No overwrite conflict — safe to proceed.'
]);
?>
