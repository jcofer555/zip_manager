<?php
$input     = $_GET['input'] ?? '';
$output    = $_GET['output'] ?? '';
$password  = $_GET['password'] ?? '';
$passArg   = $password ? "-p" . escapeshellarg($password) : "";
$logFile   = '/boot/config/plugins/zip_manager/logs/extractor_debug.log';
$logFile2  = '/boot/config/plugins/zip_manager/logs/extractor_history.log';

function overwriteLog(string $logFile, string $newLogContent): void {
    file_put_contents($logFile, rtrim($newLogContent) . "\n\n");
}

function applyOwnershipAndPermissionsFromBA(string $archivePath, string $extractRoot, int $uid = 99, int $gid = 100, ?string $logFile = null, string $password = ''): array {
    $logs = [];
    $listCmd = "/usr/bin/7zzs l -ba " . escapeshellarg($archivePath);
    if ($password) $listCmd .= " -p" . escapeshellarg($password);

    exec($listCmd . " 2>&1", $listOutput, $exitCode);
    $outputStr = implode("\n", $listOutput);

    if (strpos($outputStr, 'Enter password') !== false || strpos($outputStr, 'Wrong password') !== false) {
        $logs[] = "❌ Password required or incorrect when listing archive.";
        return $logs;
    }

    if ($exitCode !== 0) {
        $logs[] = "❌ Failed to list archive with -ba.\nCommand: $listCmd\nExit: $exitCode\nOutput:\n$outputStr";
        return $logs;
    }

    foreach ($listOutput as $line) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', trim($line))) {
            $parts = preg_split('/\s+/', $line, 6);
            if (isset($parts[5])) {
                $fullPath = rtrim($extractRoot, '/') . '/' . trim($parts[5], '/');
                $realPath = realpath($fullPath);

                if ($realPath && file_exists($realPath)) {
                    $cmd = "chown {$uid}:{$gid} " . escapeshellarg($realPath) . " 2>&1";
                    exec($cmd, $chownOut, $chownCode);
                    $perm = is_dir($realPath) ? 0777 : 0666;

                    $logs[] = ($chownCode === 0)
                        ? "✅ chown: $realPath → nobody:users"
                        : "❌ chown failed: $realPath\n" . implode("\n", $chownOut);
                    $logs[] = chmod($realPath, $perm)
                        ? "✅ chmod: $realPath → " . decoct($perm)
                        : "❌ chmod failed: $realPath";
                } else {
                    $logs[] = "⚠️ Missing file: $fullPath";
                }
            }
        }
    }

    return $logs;
}

// ✅ Validate input/output
$errors = [];
if (!file_exists($input)) {
    $errors[] = "❌ Archive not found: $input";
}
if (!is_dir($output)) {
    $errors[] = "❌ Invalid output directory.";
}
if ($errors) {
    echo implode("\n", $errors);
    exit;
}

// 🔍 Format detection
$isTarGz     = preg_match('/\.tar\.gz$/i', $input);
$isTarZst    = preg_match('/\.tar\.zst$/i', $input);
$isTar       = $isTarGz || $isTarZst;
$isRarLike   = preg_match('/\.(rar|cbr)$/i', $input);

$tmpTarPath = $isTar ? rtrim($output, '/') . '/decompressed_archive.tar' : null;

// 🔐 Password test
exec("/usr/bin/7zzs t -pwrongpassword " . escapeshellarg($input), $testOutput, $testCode);
$isEncrypted = false;
foreach ($testOutput as $line) {
    if (preg_match('/Wrong password|Can not open|Errors:|Can\'t open/', $line)) {
        $isEncrypted = true;
        break;
    }
}

// 🧰 Conflict check
exec("/usr/bin/7zzs l $passArg -slt " . escapeshellarg($input), $rawOutput, $code);
if ($code !== 0) {
    echo json_encode(['error' => 'Failed to inspect archive']);
    exit;
}

$archiveFiles = [];
foreach ($rawOutput as $line) {
    if (strpos($line, 'Path = ') === 0) {
        $file = trim(substr($line, 7));
        if ($file && substr($file, -1) !== '/') $archiveFiles[] = $file;
    }
}
$conflicts = array_filter($archiveFiles, fn($f) => file_exists($output . '/' . $f));

// 🔄 Extraction logic
if ($isTar) {
    if (file_exists($tmpTarPath)) @unlink($tmpTarPath);

    $cmd = $isTarGz
        ? "gzip -dc " . escapeshellarg($input) . " > " . escapeshellarg($tmpTarPath)
        : "zstd -d --force -o " . escapeshellarg($tmpTarPath) . " " . escapeshellarg($input);

    exec($cmd, $null, $exit);
    if ($exit !== 0) {
        echo "❌ Decompression failed.";
        exit;
    }
    $extractCmd = "/usr/bin/tar -xf " . escapeshellarg($tmpTarPath) . " -C " . escapeshellarg($output);
} elseif ($isRarLike) {
    $extractCmd = empty($password)
        ? "/usr/bin/unrar x -o+ " . escapeshellarg($input) . " " . escapeshellarg($output)
        : "/usr/bin/unrar x -p" . escapeshellarg($password) . " -o+ " . escapeshellarg($input) . " " . escapeshellarg($output);
} else {
    $extractCmd = "/usr/bin/7zzs x " . escapeshellarg($input) . " -o" . escapeshellarg($output) . " -y";
    if ($password) $extractCmd .= " -p" . escapeshellarg($password);
}

// 🚀 Extraction
exec($extractCmd, $extractOutput, $exitCode);
$extractOutputStr = implode("\n", $extractOutput);

if (preg_match('/Wrong password|Enter password/', $extractOutputStr)) {
    echo "❌ Incorrect or missing password.";
    exit;
}
if ($exitCode !== 0) {
    echo "❌ Extraction error:\n" . htmlspecialchars($extractOutputStr);
    exit;
}
echo "✅ Extraction completed.";

// 🧹 Cleanup
if ($isTar && file_exists($tmpTarPath)) @unlink($tmpTarPath);

// 🕘 History
$entry = "[" . date("Y-m-d H:i:s") . "] " . ($exitCode === 0 ? "✅ Success" : "❌ Failure") . ": $input → $output";
$history = file_exists($logFile2) ? array_slice(file($logFile2, FILE_IGNORE_NEW_LINES), -9) : [];
$history[] = $entry;
file_put_contents($logFile2, implode("\n", $history) . "\n");

// 🔐 Permissions
$permLogs = applyOwnershipAndPermissionsFromBA($input, $output, 99, 100, null, $password);

// 📝 Debug log
$logLines[] = "=== Extraction started ===";
$logLines[] = "⏰ Timestamp: " . date("Y-m-d H:i:s");
$logLines[] = "📦 Input: $input";
$logLines[] = "📤 Output: $output";
$logLines[] = $isEncrypted ? "🔐 Encrypted archive detected" : "✅ Archive not encrypted";
$logLines[] = $conflicts ? "⚠️ Conflicts:\n- " . implode("\n- ", $conflicts) : "✅ No conflicts";
$logLines[] = "🔧 Command:\n$extractCmd";
$logLines[] = "📥 Output:\n$extractOutputStr";
$logLines[] = "🔚 Exit code: $exitCode";
$logLines[] = implode("\n", $permLogs);
$logLines[] = "=== Extraction ended ===";

overwriteLog($logFile, implode("\n\n", array_filter($logLines)));
?>
