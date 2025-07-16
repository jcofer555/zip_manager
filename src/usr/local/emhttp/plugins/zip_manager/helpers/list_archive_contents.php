<?php
$input = $_GET['input'] ?? '';
$password = $_GET['password'] ?? '';

if (!$input || !file_exists($input)) {
    echo "❌ Archive not found.";
    exit;
}

$cmd = "/usr/bin/7zzs l -ba " . escapeshellarg($input);
if (!empty($password)) {
    $cmd .= " -p" . escapeshellarg($password);
}
$cmd .= " 2>&1";

$output = shell_exec($cmd);

// Lowercase output to make checks easier
$lowOutput = strtolower($output);

// Check encryption hints (you can add more strings as needed)
$encryptionIndicators = ['encrypted', '7za aes', 'password', 'headers are encrypted'];

$isEncrypted = false;
foreach ($encryptionIndicators as $indicator) {
    if (strpos($lowOutput, $indicator) !== false) {
        $isEncrypted = true;
        break;
    }
}

// Detect wrong password
if (strpos($lowOutput, 'wrong password') !== false) {
    http_response_code(403);
    echo "❌ Wrong password.";
    exit;
}

// Parse file names
$lines = explode("\n", $output);
$fileNames = [];

$pattern = '/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+[\.DRA]{5,}\s+\d+\s+\d+\s+(.+)$/';

foreach ($lines as $line) {
    if (preg_match($pattern, $line, $matches)) {
        $fileNames[] = $matches[1];
    }
}

// Handle empty file list + encryption hint = missing password
if ($isEncrypted && empty($fileNames)) {
    if (empty($password)) {
        http_response_code(403);
        echo "❌ Password required for this archive.";
        exit;
    }
}

// Empty archive
if (empty($fileNames)) {
    echo "⚠️ No files found in archive.";
    exit;
}

// Sort output
$ext = strtolower(pathinfo($input, PATHINFO_EXTENSION));
if ($ext === 'rar') {
    $fileNames = array_reverse($fileNames);
} else {
    sort($fileNames, SORT_NATURAL | SORT_FLAG_CASE);
}

foreach ($fileNames as $file) {
    echo htmlspecialchars($file, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
}
