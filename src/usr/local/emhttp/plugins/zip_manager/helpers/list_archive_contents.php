<?php
$input = $_GET['input'] ?? '';
$password = $_GET['password'] ?? '';

if (!$input || !file_exists($input)) {
    echo "❌ Archive not found.";
    exit;
}

// Detect extensions
$ext = strtolower($input);
$isTarGz  = preg_match('/\.tar\.gz$/', $ext);
$isTarZst = preg_match('/\.tar\.zst$/', $ext);

// Choose command and pattern based on format
if ($isTarGz) {
    $cmd = "tar -tzf " . escapeshellarg($input);
    $pattern = '/^(.+)$/'; // Only filenames
} elseif ($isTarZst) {
    $cmd = "tar --use-compress-program=zstd -tf " . escapeshellarg($input);
    $pattern = '/^(.+)$/'; // Only filenames
} else {
    $cmd = "/usr/bin/7zzs l -ba " . escapeshellarg($input);
    if (!empty($password)) {
        $cmd .= " -p" . escapeshellarg($password);
    }
    $pattern = '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+[\.DRA]{5,}\s+\d+\s+\d+\s+(.+)$/';
}

$cmd .= " 2>&1";
$output = shell_exec($cmd);
$lowOutput = strtolower($output);

// Encryption check (7z/rar only)
$encryptionIndicators = ['encrypted', '7za aes', 'password', 'headers are encrypted'];
$isEncrypted = false;
foreach ($encryptionIndicators as $indicator) {
    if (strpos($lowOutput, $indicator) !== false) {
        $isEncrypted = true;
        break;
    }
}

// Detect wrong password (7z/rar only)
if (strpos($lowOutput, 'wrong password') !== false) {
    http_response_code(403);
    echo "❌ Wrong password.";
    exit;
}

// Parse file names
$lines = explode("\n", $output);
$fileNames = [];

foreach ($lines as $line) {
    if (preg_match($pattern, $line, $matches)) {
        $fileNames[] = $matches[1];
    }
}

// Password required (7z/rar only)
if ($isEncrypted && empty($fileNames) && empty($password)) {
    http_response_code(403);
    echo "❌ Password required for this archive.";
    exit;
}

if (empty($fileNames)) {
    echo "⚠️ No files found in archive.";
    exit;
}

// Sort output
if (preg_match('/\.rar$/', $ext)) {
    $fileNames = array_reverse($fileNames);
} else {
    sort($fileNames, SORT_NATURAL | SORT_FLAG_CASE);
}

// Output
foreach ($fileNames as $file) {
    echo htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
}
