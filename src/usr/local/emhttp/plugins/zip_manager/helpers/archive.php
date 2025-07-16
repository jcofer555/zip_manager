<?php
$inputRaw = $_GET['input'] ?? '';
$output   = $_GET['output'] ?? '';
$password = $_GET['password'] ?? '';
$format   = $_GET['format'] ?? '7z';
$name     = $_GET['name']   ?? 'archive';
$logFile      = '/boot/config/plugins/zip_manager/logs/archiver_debug.log';
$logFile2     = '/boot/config/plugins/zip_manager/logs/archiver_history.log';

function overwriteLog(string $logFile, string $newLogContent): void {
  $newLogContent = rtrim($newLogContent) . "\n\n";
  file_put_contents($logFile, $newLogContent);
}

function getFolderSize(string $path): int {
  $size = 0;
  $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $size += $file->getSize();
    }
  }
  return $size;
}

// ✅ Split and validate each input
$inputList = array_filter(array_map('trim', explode(',', $inputRaw)));
$validInputs = [];
$totalSize = 0;

foreach ($inputList as $entry) {
  if (!file_exists($entry)) exit("❌ Missing input path: $entry");
  $validInputs[] = $entry;

  $entrySize = is_dir($entry) ? getFolderSize($entry) : filesize($entry);
  $totalSize += $entrySize;
}

if (empty($validInputs)) exit("❌ No valid input paths specified.");
if (!is_dir($output)) exit("❌ Output directory not valid.");

// ✅ Archive name logic
$name = preg_replace('/(\.tar\.gz|\.tar|\.zip|\.rar|\.7z)$/i', '', $name);
$archiveName = ($format === 'tar.gz') ? $name . '.tar.gz' : $name . '.' . $format;
$archivePath = rtrim($output, '/') . '/' . $archiveName;

// ✅ Remove existing archive
if (file_exists($archivePath)) {
  @unlink($archivePath);
}

// ✅ Build archive command
$exitCode = -1;
$cmdOutput = [];
$cmdOutputStr = '';

if ($format === 'tar.gz') {
  $tarPath = "/tmp/intermediate_archive.tar";

  $cmd1 = "/usr/bin/7zzs a -ttar " . escapeshellarg($tarPath);
  foreach ($validInputs as $entry) {
    $cmd1 .= " " . escapeshellarg($entry);
  }

  exec($cmd1 . " 2>&1", $out1, $code1);

  $cmd2 = "/usr/bin/7zzs a -tgzip " . escapeshellarg($archivePath) . " " . escapeshellarg($tarPath);
  exec($cmd2 . " 2>&1", $out2, $code2);

  @unlink($tarPath);

  $cmdOutput    = array_merge($out1, $out2);
  $cmdOutputStr = implode("\n", $cmdOutput);
  $exitCode     = ($code1 === 0 && $code2 === 0) ? 0 : 1;
} else {
  if ($format === 'rar') {
    $cmd = "/usr/bin/rar a " . escapeshellarg($archivePath);
  } else {
    $cmd = "/usr/bin/7zzs a -t{$format} " . escapeshellarg($archivePath);
  }

  foreach ($validInputs as $entry) {
    $cmd .= " " . escapeshellarg($entry);
  }

  if (!empty($password)) {
    $cmd .= " -p" . escapeshellarg($password);
  }

  exec($cmd . " 2>&1", $cmdOutput, $exitCode);
  $cmdOutputStr = implode("\n", $cmdOutput);
}

// ✅ History log
$timestamp = date("Y-m-d H:i:s");
$status    = ($exitCode === 0) ? "✅ Success:" : "❌ Failure:";
$entry     = "[$timestamp] $status " . implode(", ", $validInputs) . " -> $archivePath";

$existing = file_exists($logFile2) ? file($logFile2, FILE_IGNORE_NEW_LINES) : [];
$existing = array_slice($existing, -9);
$existing[] = $entry;
file_put_contents($logFile2, implode("\n", $existing) . "\n");

// ✅ Ownership and permissions
$fixLogs = [];
if (file_exists($archivePath)) {
  exec("chown 99:100 " . escapeshellarg($archivePath), $chownOut, $chownCode);
  $fixLogs[] = $chownCode === 0
    ? "✅ chown applied: $archivePath -> nobody:users"
    : "❌ chown failed: $archivePath";

  $chmodSuccess = chmod($archivePath, 0666);
  $fixLogs[] = $chmodSuccess
    ? "✅ chmod applied: $archivePath -> 0666"
    : "❌ chmod failed: $archivePath";
}

// ✅ Debug log
$logRunContent  = "=== Archive creation started ===\n";
$logRunContent .= "⏰ Timestamp: $timestamp\n";
$logRunContent .= "📦 Inputs:\n" . implode("\n", $validInputs) . "\n";
$logRunContent .= "📤 Output: $archivePath\n";
$logRunContent .= "📏 Combined Size: " . round($totalSize / (1024 * 1024), 2) . " MB\n";
$logRunContent .= $password ? "🔐 Password protected\n" : "🔓 No password\n";

if (isset($cmd)) $logRunContent .= "🛠️ Command:\n$cmd\n\n";
if ($format === 'tar.gz') $logRunContent .= "🛠️ Commands:\n$cmd1\n$cmd2\n\n";

$logRunContent .= "📥 Output:\n$cmdOutputStr\n\n";
$logRunContent .= "🔚 Exit code: $exitCode\n";
$logRunContent .= implode("\n", $fixLogs) . "\n";
$logRunContent .= $exitCode === 0
  ? "✅ Archive created successfully.\n"
  : "❌ Archive creation failed.\n";
$logRunContent .= "=== Archive creation ended ===";

overwriteLog($logFile, $logRunContent);

// ✅ Final response
echo $exitCode === 0
  ? "✅ Archive created."
  : "❌ Archive creation failed.";
?>
