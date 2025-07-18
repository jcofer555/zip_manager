<?php
header('Content-Type: application/json');

$input    = $_GET['input']   ?? '';
$output   = $_GET['output']  ?? '';
$password = $_GET['password']?? '';
$passArg  = $password ? "-p" . escapeshellarg($password) : "";

// 🧪 Basic input sanity check
if (!$input || !$output) {
  echo json_encode(['error' => 'Missing input or output']);
  exit;
}

// 🧪 Clean output path
$output = rtrim($output, '/') . '/';

// 🧪 Detect archive type
$isTarGz      = preg_match('/\.tar\.gz$/i', $input);
$isTarZst     = preg_match('/\.tar\.zst$/i', $input);
$isTarArchive = $isTarGz || $isTarZst;

// === Step 1: List archive contents
$rawOutput = [];
$archiveFiles = [];

if ($isTarArchive) {
  $tmpTarPath = sys_get_temp_dir() . '/decompressed_archive.tar';
  if (file_exists($tmpTarPath)) @unlink($tmpTarPath);

  $decompressCmd = $isTarGz
    ? "gzip -dc " . escapeshellarg($input) . " > " . escapeshellarg($tmpTarPath)
    : "zstd -d --force -o " . escapeshellarg($tmpTarPath) . " " . escapeshellarg($input);

  exec($decompressCmd . " 2>&1", $decompOut, $decompCode);

  if ($decompCode !== 0 || !file_exists($tmpTarPath)) {
    echo json_encode(['error' => 'Failed to decompress tarball']);
    exit;
  }

  exec("/usr/bin/tar -tf " . escapeshellarg($tmpTarPath), $rawOutput, $code);
  @unlink($tmpTarPath);

} else {
  exec("/usr/bin/7zzs l $passArg -slt " . escapeshellarg($input), $rawOutput, $code);
}

if ($code !== 0) {
  echo json_encode(['error' => 'Failed to read archive contents']);
  exit;
}

// === Step 2: Parse file paths
foreach ($rawOutput as $line) {
  $line = trim($line);
  if ($isTarArchive) {
    if ($line !== '' && substr($line, -1) !== '/') {
      $archiveFiles[] = $line;
    }
  } else {
    if (strpos($line, 'Path = ') === 0) {
      $path = trim(substr($line, 7));
      if ($path !== '' && substr($path, -1) !== '/') {
        $archiveFiles[] = $path;
      }
    }
  }
}

// === Step 3: Check for conflicts
$conflicts = [];
foreach ($archiveFiles as $relPath) {
  $targetPath = $output . $relPath;
  if (file_exists($targetPath)) {
    $conflicts[] = $relPath;
  }
}

// === Final output
echo json_encode([
  'conflicts' => $conflicts,
  'count'     => count($conflicts)
]);
