<?php
$input = $_GET['input'] ?? '';
$output = $_GET['output'] ?? '';
$password = $_GET['password'] ?? '';
$passArg = $password ? "-p" . escapeshellarg($password) : "";
$maxSizeBytes = 500 * 1024 * 1024; // 500 MB limit
$logFile = '/boot/config/plugins/zip_manager/logs/extractor_debug.log';

// Always overwrite log with the last run only
function overwriteLog(string $logFile, string $newLogContent): void {
    $newLogContent = rtrim($newLogContent) . "\n\n";
    file_put_contents($logFile, $newLogContent);
}

function applyOwnershipAndPermissionsFromBA(
    string $archivePath,
    string $extractRoot,
    int $uid = 99,
    int $gid = 100,
    ?string $logFile = null,
    string $password = ''
): array {
    $logs = [];

    $listCmd = "/usr/bin/7zzs l -ba " . escapeshellarg($archivePath);
    if (!empty($password)) {
        $listCmd .= " -p" . escapeshellarg($password);
    }

    exec($listCmd . " 2>&1", $listOutput, $exitCode);

    $outputStr = implode("\n", $listOutput);
    if (strpos($outputStr, 'Enter password') !== false || strpos($outputStr, 'Wrong password') !== false) {
        $logs[] = "❌ Password required or incorrect when listing archive.";
        return $logs;
    }

    if ($exitCode !== 0) {
        $logs[] = "❌ Failed to list archive contents using -ba.\nCommand: $listCmd\nExit: $exitCode\nOutput:\n" . implode("\n", $listOutput);
        return $logs;
    }

    $filePaths = [];
    foreach ($listOutput as $line) {
        $line = trim($line);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $line)) {
            $parts = preg_split('/\s+/', $line, 6);
            if (isset($parts[5])) {
                $relative = trim($parts[5], '/');
                $fullPath = rtrim($extractRoot, '/') . '/' . $relative;
                $realFullPath = realpath($fullPath);

                if ($realFullPath && file_exists($realFullPath)) {
                    $filePaths[] = $realFullPath;
                } else {
                    $logs[] = "⚠️ File not found after extraction: $fullPath";
                }
            }
        }
    }

    $logs[] = "📝 Found " . count($filePaths) . " file(s) to apply ownership and permissions to:\n- " . implode("\n- ", $filePaths);

    foreach ($filePaths as $path) {
        $cmd = "chown {$uid}:{$gid} " . escapeshellarg($path) . " 2>&1";
        exec($cmd, $chownOut, $chownCode);
        $chownOutput = implode("\n", $chownOut);

        if ($chownCode === 0) {
            $logs[] = "✅ chown succeeded: $path -> nobody:users";
        } else {
            $logs[] = "❌ chown failed: $path\nExit code: $chownCode\nOutput:\n$chownOutput";
        }

        $perm = is_dir($path) ? 0777 : 0666;
        $chmodSuccess = chmod($path, $perm);

        if ($chmodSuccess) {
            $logs[] = "✅ chmod applied: $path -> " . decoct($perm);
        } else {
            $logs[] = "❌ chmod failed: $path -> " . decoct($perm);
        }
    }

    return $logs;
}

// Validate input/output
if (!file_exists($input)) {
    echo "❌ Archive not found.";
    exit;
}

if (!is_dir($output)) {
    echo "❌ Output directory not valid.";
    exit;
}

// Step 0: Check if archive is encrypted (test with wrong password)
exec("/usr/bin/7zzs t -pwrongpassword " . escapeshellarg($input) . " 2>&1", $testOutput, $testCode);

$isEncrypted = false;
foreach ($testOutput as $line) {
    if (
        strpos($line, 'Wrong password') !== false ||
        strpos($line, 'Can not open encrypted archive') !== false ||
        strpos($line, 'Errors:') !== false ||
        strpos($line, "Can't open as archive") !== false
    ) {
        $isEncrypted = true;
        break;
    }
}

// Step 1: Pre-check archive content size
$listCmd = "/usr/bin/7zzs l -slt " . escapeshellarg($input);
if (!empty($password)) {
    $listCmd .= " -p" . escapeshellarg($password);
}

exec($listCmd . " 2>&1", $listOutput, $listExitCode);

$outputStr = implode("\n", $listOutput);
if (strpos($outputStr, 'Wrong password') !== false || strpos($outputStr, 'Enter password') !== false) {
    echo "❌ Check or enter your password.";
    exit;
}

if ($listExitCode !== 0) {
    echo "❌ Failed to read archive. Possibly corrupted or encrypted.";
    exit;
}

// Step 2: Parse and sum file sizes
$totalSize = 0;
foreach ($listOutput as $line) {
    if (preg_match('/^Size = (\d+)/', $line, $matches)) {
        $totalSize += (int)$matches[1];
    }
}

if ($totalSize > $maxSizeBytes) {
    $mb = round($totalSize / (1024 * 1024), 2);
    echo "❌ Archive uncompressed size is ${mb} MB — exceeds 500 MB limit. Extraction aborted.";
    exit;
}

// Step 2.5: Detect overwrite conflicts
exec("/usr/bin/7zzs l $passArg -slt " . escapeshellarg($input), $rawOutput, $code);
if ($code !== 0) {
  echo json_encode(['error' => 'Failed to read archive contents']);
  exit;
}

$archiveFiles = [];
foreach ($rawOutput as $line) {
  if (strpos($line, 'Path = ') === 0) {
    $path = trim(substr($line, 7));
    if ($path && substr($path, -1) !== '/') {
      $archiveFiles[] = $path;
    }
  }
}

$conflicts = [];
foreach ($archiveFiles as $relPath) {
  $targetPath = $output . $relPath;
  if (file_exists($targetPath)) {
    $conflicts[] = $relPath;
  }
}

// Step 3: Proceed with extraction
$extractCmd = "/usr/bin/7zzs x " . escapeshellarg($input) .
              " -o" . escapeshellarg($output) .
              " -y";

if (!empty($password)) {
    $extractCmd .= " -p" . escapeshellarg($password);
}

exec($extractCmd . " 2>&1", $extractOutput, $extractExitCode);
$extractOutputStr = implode("\n", $extractOutput);

if (strpos($extractOutputStr, 'Wrong password') !== false || strpos($extractOutputStr, 'Enter password') !== false) {
    echo "❌ Check or enter your password.";
    exit;
}

if ($extractExitCode !== 0) {
    echo "❌ Extraction failed.\n\n" . htmlspecialchars($extractOutputStr);
    exit;
}

echo "✅ Extraction completed.";

$logFile2 = '/boot/config/plugins/zip_manager/logs/extractor_history.log';
$timestamp = date("Y-m-d H:i:s");
$entry = "[$timestamp] $input -> $output";

// Read existing log (if any), keep max 9 previous entries
$existing = file_exists($logFile2) ? file($logFile2, FILE_IGNORE_NEW_LINES) : [];
$existing = array_slice($existing, -9); // Keep only last 9

$existing[] = $entry; // Add new
file_put_contents($logFile2, implode("\n", $existing) . "\n");

// Step 4: Apply ownership/permissions
$chownChmodLogs = applyOwnershipAndPermissionsFromBA(
    $input,
    $output,
    99,
    100,
    null,
    $password
);

// Step 5: Log entire session
$inputFile = realpath($input) ?: $input;

$logRunContent = "=== Extraction for {$inputFile} started ===\n";
$logRunContent .= $isEncrypted ? "🔐 Archive is encrypted.\n\n" : "✅ Archive is not encrypted.\n\n";
$logRunContent .= "📁 Conflict check:\n";
if (count($conflicts)) {
    $logRunContent .= "⚠️ Conflicting file(s) detected (" . count($conflicts) . "):\n- " . implode("\n- ", $conflicts) . "\n\n";
} else {
    $logRunContent .= "✅ No conflicting files detected.\n\n";
}
$logRunContent .= "🔧 Extraction command:\n$extractCmd\n\n";
$logRunContent .= "📥 Extraction output:\n$extractOutputStr\n\n";
$logRunContent .= "🔚 Extraction exit code: $extractExitCode\n\n";
$logRunContent .= implode("\n", $chownChmodLogs) . "\n";
$logRunContent .= "=== Extraction for {$inputFile} ended ===";

overwriteLog($logFile, $logRunContent);
