<?php
$inputRaw = $_GET['input'] ?? '';
$output   = $_GET['output'] ?? '';
$password = $_GET['password'] ?? '';
$format   = $_GET['format'] ?? '7z';
$name     = $_GET['name']   ?? 'archive';
$logFile  = '/boot/config/plugins/zip_manager/logs/archiver_debug.log';
$logFile2 = '/boot/config/plugins/zip_manager/logs/archiver_history.log';

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

function getBestMntPath(): string {
  $excluded = ['/mnt/user', '/mnt/user0', '/mnt/addons', '/mnt/rootshare'];
  $bestPath = '/tmp';
  $maxSpace = 0;
  $minRequired = 10 * 1024 * 1024 * 1024;

  foreach (glob('/mnt/*', GLOB_ONLYDIR) as $mntPath) {
    if (in_array($mntPath, $excluded, true) || !is_writable($mntPath)) continue;
    $space = disk_free_space($mntPath);
    if ($space >= $minRequired && $space > $maxSpace) {
      $maxSpace = $space;
      $bestPath = $mntPath;
    }
  }
  return $bestPath;
}

function cleanupIntermediateTar(string $path, int $maxAgeSeconds = 3600): void {
  if (is_file($path) && (time() - filemtime($path)) > $maxAgeSeconds) {
    @unlink($path);
  }
}

function getCpuCountFromLscpu(): int {
  $output = shell_exec("lscpu | grep '^CPU(s):'");
  preg_match('/CPU\(s\):\s+(\d+)/', $output, $matches);
  return isset($matches[1]) ? (int)$matches[1] : 1;
}

// ✅ Error collection
$errors = [];

// ✅ Input validation
$inputList = array_filter(array_map('trim', explode(',', $inputRaw)));
$validInputs = [];
$totalSize = 0;

foreach ($inputList as $entry) {
  if (!file_exists($entry)) {
    $errors[] = "❌ Missing input path: $entry";
    continue;
  }

  $resolvedInput = realpath($entry);
  if ($resolvedInput === false) {
    $errors[] = "❌ Could not resolve input path: $entry";
    continue;
  }

  $normalizedInput = rtrim($resolvedInput, '/');
  $inputDepth = substr_count($normalizedInput, '/');
  if ($inputDepth <= 2) {
    $errors[] = "❌ Input path is not allowed: $resolvedInput";
    continue;
  }

  $validInputs[] = $entry;
  $entrySize = is_dir($entry) ? getFolderSize($entry) : filesize($entry);
  $totalSize += $entrySize;
}

// ✅ Output validation
if (!is_dir($output)) {
  $errors[] = "❌ Output directory not valid.";
} else {
  $resolvedOutput = realpath($output);
  if ($resolvedOutput === false) {
    $errors[] = "❌ Could not resolve output path.";
  } else {
    $normalizedOutput = rtrim($resolvedOutput, '/');
    $outputDepth = substr_count($normalizedOutput, '/');
    if ($outputDepth <= 2) {
      $errors[] = "❌ Output path is not allowed: $resolvedOutput";
    }
  }
}

// ✅ Report and exit on errors
if (!empty($errors)) {
  echo implode("\n", $errors);
  exit;
}

// ✅ Archive name
$name = preg_replace('/(\.tar\.gz|\.tar\.zst|\.tar|\.zip|\.rar|\.7z|\.cbz|\.cbr)$/i', '', $name);
if ($format === 'zstd') {
  $archiveName = $name . '.tar.zst';
} elseif ($format === 'tar.gz') {
  $archiveName = $name . '.tar.gz';
} elseif ($format === 'cbz') {
  $archiveName = $name . '.cbz';
} elseif ($format === 'cbr') {
  $archiveName = $name . '.cbr';
} else {
  $archiveName = $name . '.' . $format;
}
$archivePath = rtrim($output, '/') . '/' . $archiveName;

// ✅ Remove existing archive
if (file_exists($archivePath)) {
  @unlink($archivePath);
}

// ✅ Archive command execution
$exitCode = -1;
$cmdOutput = [];
$cmdOutputStr = '';

if ($format === 'tar.gz') {
  $cmd = "/usr/bin/tar -czf " . escapeshellarg($archivePath);
  foreach ($validInputs as $entry) {
    $cmd .= " -C " . escapeshellarg(dirname($entry)) . " " . escapeshellarg(basename($entry));
  }
  exec($cmd . " 2>&1", $cmdOutput, $exitCode);
  $cmdOutputStr = implode("\n", $cmdOutput);

} elseif ($format === 'tar') {
  $cmd = "/usr/bin/tar -cf " . escapeshellarg($archivePath);
  foreach ($validInputs as $entry) {
    $cmd .= " -C " . escapeshellarg(dirname($entry)) . " " . escapeshellarg(basename($entry));
  }
  exec($cmd . " 2>&1", $cmdOutput, $exitCode);
  $cmdOutputStr = implode("\n", $cmdOutput);

} elseif ($format === 'zstd') {
  $bestTempDir = getBestMntPath();
  $tarPath = rtrim($bestTempDir, '/') . '/intermediate_archive.tar';
  cleanupIntermediateTar($tarPath);

  $cmd1 = "/usr/bin/tar -cf " . escapeshellarg($tarPath);
  foreach ($validInputs as $entry) {
    $cmd1 .= " -C " . escapeshellarg(dirname($entry)) . " " . escapeshellarg(basename($entry));
  }
  exec($cmd1 . " 2>&1", $out1, $code1);

  $threads = max(1, intdiv(getCpuCountFromLscpu(), 2));
  $cmd2 = "/usr/bin/zstd --verbose -f --threads={$threads} -o " . escapeshellarg($archivePath) . " " . escapeshellarg($tarPath);
  exec($cmd2 . " 2>&1", $out2, $code2);

  @unlink($tarPath);

  $cmdOutput = array_merge($out1, $out2);
  $cmdOutputStr = implode("\n", $cmdOutput);
  $exitCode = ($code1 === 0 && $code2 === 0) ? 0 : 1;

} else {
  if ($format === 'cbz') {
    $cmd = "/usr/bin/7zzs a -tzip " . escapeshellarg($archivePath);
  } elseif ($format === 'cbr') {
    $cmd = "/usr/bin/rar a " . escapeshellarg($archivePath);
  } elseif ($format === 'rar') {
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
$existing = array_slice($existing, -19);
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
$logLines[] = $password ? "🔐 Password protected" : "🔓 No password";
$logLines[] = isset($cmd) ? "🛠️ Command:\n$cmd\n" : null;
if ($format === 'zstd') {
  $logLines[] = "🛠️ Commands:\n$cmd1\n$cmd2\n";
}

// 🧼 Filter noisy trial and evaluation messages from output
if ($cmdOutputStr) {
  $excludedMessages = [
    "Trial version             Type 'rar -?' for help",
    "Evaluation copy. Please register.",
  ];

  $filteredCmdOutput = implode("\n", array_filter(
    explode("\n", $cmdOutputStr),
    fn($line) => !in_array(trim($line), $excludedMessages)
  ));

  $logLines[] = "📥 Output:\n$filteredCmdOutput\n";
} else {
  $logLines[] = null;
}

$logLines[] = "🔚 Exit code: $exitCode";
$logLines[] = !empty($fixLogs) ? implode("\n", $fixLogs) : null;
$logLines[] = $exitCode === 0
  ? "✅ Archive created successfully."
  : "❌ Archive creation failed.";
$logLines[] = "=== Archive creation ended ===";

$logRunContent = implode("\n", array_filter($logLines)) . "\n";
overwriteLog($logFile, $logRunContent);

// ✅ Final response
echo $exitCode === 0
  ? "✅ Archive created."
  : "❌ Archive creation failed.";
?>
