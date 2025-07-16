<?php
header('Content-Type: application/json');

$path = $_GET['path'] ?? '';
$resolved = realpath($path);

// 🔒 Validate path
if (
  !$resolved ||
  strpos($resolved, '/mnt/') !== 0 ||
  !file_exists($resolved)
) {
  echo json_encode(['bytes' => 0]);
  exit;
}

// 📁 Folder size calculation
function getFolderSize($dir) {
  $size = 0;
  try {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $size += $file->getSize();
      }
    }
  } catch (Exception $e) {
    // Handle unreadable folders or permission issues
    return 0;
  }
  return $size;
}

// ✅ File or folder size
$size = is_dir($resolved)
  ? getFolderSize($resolved)
  : filesize($resolved);

// Return size in bytes
echo json_encode(['bytes' => $size]);
