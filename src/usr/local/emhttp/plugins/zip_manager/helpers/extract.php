<?php
$input   = $_GET['input'] ?? '';
$output  = $_GET['output'] ?? '';
$password = $_GET['password'] ?? '';
$passArg = $password ? "-p" . escapeshellarg($password) : "";
$logFile  = '/boot/config/plugins/zip_manager/logs/extractor_debug.log';
$logFile2 = '/boot/config/plugins/zip_manager/logs/extractor_history.log';

function overwriteLog(string $logFile, string $newLogContent): void {
    $newLogContent = rtrim($newLogContent) . "\n\n";
    file_put_contents($logFile, $newLogContent);
}

function applyOwnershipAndPermissionsFromBA(string $archivePath, string $extractRoot, int $uid = 99, int $gid = 100, ?string $logFile = null, string $password = ''): array {
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
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($line))) {
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

        $logs[] = ($chownCode === 0)
            ? "✅ chown succeeded: $path -> nobody:users"
            : "❌ chown failed: $path\nExit code: $chownCode\nOutput:\n$chownOutput";

        $perm = is_dir($path) ? 0777 : 0666;
        $logs[] = chmod($path, $perm)
            ? "✅ chmod applied: $path -> " . decoct($perm)
            : "❌ chmod failed: $path -> " . decoct($perm);
    }

    return $logs;
}

// ✅ Validate input/output paths
$errors = [];

if (!file_exists($input)) {
    $errors[] = "❌ Archive file not found: $input";
} else {
    $realInput = realpath($input);
    if ($realInput === false) {
        $errors[] = "❌ Input path could not be resolved.";
    } else {
        $inputDepth = substr_count(rtrim($realInput, '/'), '/');
        if ($inputDepth <= 2) {
            $errors[] = "❌ Input path is not allowed: $realInput";
        }
    }
}

if (!is_dir($output)) {
    $errors[] = "❌ Output directory not valid.";
} else {
    $realOutput = realpath($output);
    if ($realOutput === false) {
        $errors[] = "❌ Output path could not be resolved.";
    } else {
        $outputDepth = substr_count(rtrim($realOutput, '/'), '/');
        if ($outputDepth <= 2) {
            $errors[] = "❌ Output path is not allowed: $realOutput";
        }
    }
}

if (!empty($errors)) {
    echo implode("\n", $errors);
    exit;
}

// ✅ Archive type check
$isTarGz  = preg_match('/\.tar\.gz$/i', $input);
$isTarZst = preg_match('/\.tar\.zst$/i', $input);
$isTarArchive = $isTarGz || $isTarZst;
$tmpTarPath = $isTarArchive ? rtrim($output, '/') . '/decompressed_archive.tar' : null;

// ✅ Encryption check
exec("/usr/bin/7zzs t -pwrongpassword " . escapeshellarg($input) . " 2>&1", $testOutput, $testCode);
$isEncrypted = false;
foreach ($testOutput as $line) {
    if (strpos($line, 'Wrong password') !== false || strpos($line, 'Can not open encrypted archive') !== false || strpos($line, 'Errors:') !== false || strpos($line, "Can't open as archive") !== false) {
        $isEncrypted = true;
        break;
    }
}

// ✅ Conflict detection
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
    $targetPath = $output . '/' . $relPath;
    if (file_exists($targetPath)) {
        $conflicts[] = $relPath;
    }
}

// ✅ Prepare extraction command
$extractCmd = '';
if ($isTarArchive) {
    if (file_exists($tmpTarPath)) @unlink($tmpTarPath);

    $cmd = $isTarGz
        ? "gzip -dc " . escapeshellarg($input) . " > " . escapeshellarg($tmpTarPath)
        : "zstd -d --force -o " . escapeshellarg($tmpTarPath) . " " . escapeshellarg($input);

    exec($cmd . " 2>&1", $decompressOutput, $decompressExitCode);
    if ($decompressExitCode !== 0) {
        echo "❌ Failed to decompress archive.";
        exit;
    }

    $extractCmd = "/usr/bin/tar -xf " . escapeshellarg($tmpTarPath) . " -C " . escapeshellarg($output);
} else {
    if (preg_match('/\.rar$/i', $input)) {
        $extractCmd = empty($password)
            ? "/usr/bin/unrar x -o+ " . escapeshellarg($input) . " " . escapeshellarg($output)
            : "/usr/bin/unrar x -p" . escapeshellarg($password) . " -o+ " . escapeshellarg($input) . " " . escapeshellarg($output);
    } else {
        $extractCmd = "/usr/bin/7zzs x " . escapeshellarg($input) . " -o" . escapeshellarg($output) . " -y";
        if (!empty($password)) {
            $extractCmd .= " -p" . escapeshellarg($password);
        }
    }
}

// ✅ Perform extraction
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

// ✅ Cleanup
if ($isTarArchive && file_exists($tmpTarPath)) {
    @unlink($tmpTarPath);
}

// ✅ History log
$timestamp = date("Y-m-d H:i:s");
$status = ($extractExitCode === 0) ? "✅ Success:" : "❌ Failure:";
$entry = "[$timestamp] $status $input -> $output";

$existing = file_exists($logFile2) ? file($logFile2, FILE_IGNORE_NEW_LINES) : [];
$existing = array_slice($existing, -9);
$existing[] = $entry;
file_put_contents($logFile2, implode("\n", $existing) . "\n");

// ✅ Apply ownership/permissions
$chownChmodLogs = applyOwnershipAndPermissionsFromBA($input, $output, 99, 100, null, $password);

// ✅ Write debug log
$logLines = [];
$logLines[] = "=== Extraction started ===";
$logLines[] = "⏰ Timestamp: $timestamp";
$logLines[] = "📦 Input: $input";
$logLines[] = "📤 Output: $output";
$logLines[] = $isEncrypted ? "🔐 Archive is encrypted." : "✅ Archive is not encrypted.";
$logLines[] = "📁 Conflict check:";
$logLines[] = count($conflicts)
    ? "⚠️ Conflicting file(s) detected (" . count($conflicts) . "):\n- " . implode("\n- ", $conflicts)
    : "✅ No conflicting files detected.";
$logLines[] = "🔧 Extraction command:\n$extractCmd";
$logLines[] = "📥 Extraction output:\n$extractOutputStr";
$logLines[] = "🔚 Extraction exit code: $extractExitCode";
$logLines[] = !empty($chownChmodLogs) ? implode("\n", $chownChmodLogs) : null;
$logLines[] = "=== Extraction ended ===";

$logRunContent = implode("\n\n", array_filter($logLines)) . "\n";
overwriteLog($logFile, $logRunContent);
?>
