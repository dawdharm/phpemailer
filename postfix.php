<?php

set_time_limit(0);
ini_set('memory_limit', -1);
date_default_timezone_set('Europe/London');

define('ROOT_LOG_DIR', DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log'.DIRECTORY_SEPARATOR);

$domain_id = 0;
$domainIdFile = __DIR__ . '/domain_id.json';
if (file_exists($domainIdFile)) {
    $domain = json_decode(file_get_contents($domainIdFile), true);
    if (is_array($domain) && isset($domain['domain_id'])) {
        $domain_id = (int)$domain['domain_id'];
    }
}

$apiUrl = 'https://ingest.pay-per-lead.co.uk/postfix/logBatch';
/*
$apiConfigFile = __DIR__ . '/api_config.json';
if (file_exists($apiConfigFile)) {
    $apiConfig = json_decode(file_get_contents($apiConfigFile), true);
    if (is_array($apiConfig) && !empty($apiConfig['api_url'])) {
        // Allow either /log or /logBatch in config, normalise to /logBatch
        $apiUrl = rtrim($apiConfig['api_url'], '/');
        if (str_ends_with($apiUrl, '/log')) {
            $apiUrl = substr($apiUrl, 0, -4) . '/logBatch';
        } elseif (!str_ends_with($apiUrl, '/logBatch')) {
            $apiUrl = $apiUrl . '/logBatch';
        }
    }
}*/

$logFile = '';
if (file_exists(ROOT_LOG_DIR.'mail.log')) {
    $logFile = ROOT_LOG_DIR.'mail.log';
} elseif (file_exists(ROOT_LOG_DIR.'maillog')) {
    $logFile = ROOT_LOG_DIR.'maillog';
} else {
    echo "Error: No mail log file found\n";
    exit(1);
}

$stateFile = __DIR__ . '/mail.state.json';
$lockFile = __DIR__ . '/mail.lock';
$spoolDir = __DIR__ . '/spool';

$batchMax = 500;            // Increase from 25 to reduce HTTP overhead
$flushEverySeconds = 2.0;   // Time based flush to smooth bursts
$pollSleepUs = 250000;      // 0.25s
$heartbeatSeconds = 30;     // Print status every 30s when idle

if (!is_dir($spoolDir)) {
    mkdir($spoolDir, 0775, true);
}

acquireLock($lockFile);

$state = loadState($stateFile);
$hostname = gethostname() ?: '';
$ip = gethostbyname($hostname);

$fp = openLogFile($logFile);
$currentInode = getFileInode($logFile);
$currentOffset = isset($state['offset']) ? (int)$state['offset'] : 0;
$lastFlushAt = microtime(true);

seekSafe($fp, $currentOffset);

echo "Polling: {$logFile}\n";
echo "API: {$apiUrl}\n";

$batch = [];
$lastHeartbeat = microtime(true);
$totalSent = 0;
$linesRead = 0;

while (true) {
    // Try to send any spooled failed batches first
    drainSpool($spoolDir, $apiUrl);

    // Detect rotation by inode change
    $inodeNow = getFileInode($logFile);
    if ($inodeNow !== $currentInode) {
        fclose($fp);
        $fp = openLogFile($logFile);
        $currentInode = $inodeNow;
        $currentOffset = 0;
        seekSafe($fp, 0);
        echo "Detected rotation, reopened log file\n";
    }

    $line = fgets($fp);
    if ($line === false) {
        // Clear PHP's EOF internal flag so fgets() can see newly appended data
        clearstatcache(true, $logFile);
        fseek($fp, ftell($fp));

        usleep($pollSleepUs);

        // Still flush if time exceeded and we have a batch
        if (!empty($batch) && (microtime(true) - $lastFlushAt) >= $flushEverySeconds) {
            flushBatch($batch, $apiUrl, $spoolDir);
            $totalSent += count($batch);
            $batch = [];
            $lastFlushAt = microtime(true);

            // Save state after flush
            saveState($stateFile, [
                'inode' => $currentInode,
                'offset' => $currentOffset,
                'updated_at' => date('c'),
            ]);
        }

        // Heartbeat: show we're alive when idle
        if ((microtime(true) - $lastHeartbeat) >= $heartbeatSeconds) {
            echo "[" . date('H:i:s') . "] Waiting for new log lines... (sent: {$totalSent}, read: {$linesRead}, pending: " . count($batch) . ")\n";
            $lastHeartbeat = microtime(true);
        }

        continue;
    }

    $linesRead++;

    $currentOffset = ftell($fp);

    $row = processLogLine($line, $domain_id);
    if ($row !== null) {
        // Add useful source info
        $row['host'] = $hostname;
        $row['ip_address'] = $ip;

        $batch[] = $row;
    }

    $shouldFlushSize = count($batch) >= $batchMax;
    $shouldFlushTime = !empty($batch) && (microtime(true) - $lastFlushAt) >= $flushEverySeconds;

    if ($shouldFlushSize || $shouldFlushTime) {
        flushBatch($batch, $apiUrl, $spoolDir);
        $totalSent += count($batch);
        $batch = [];
        $lastFlushAt = microtime(true);

        // Only save state after a successful flush so no entries are lost on restart
        saveState($stateFile, [
            'inode' => $currentInode,
            'offset' => $currentOffset,
            'updated_at' => date('c'),
        ]);
    }
}

function acquireLock(string $lockFile): void {
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        $lockPid = (int)trim((string)file_get_contents($lockFile));
        $processRunning = $lockPid > 0 && file_exists("/proc/{$lockPid}");

        if ($lockAge < 300 && $processRunning) {
            echo "Already running (PID {$lockPid})\n";
            exit(0);
        }

        @unlink($lockFile);
    }

    file_put_contents($lockFile, (string)getmypid());
}

function loadState(string $stateFile): array {
    if (!file_exists($stateFile)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($stateFile), true);
    return is_array($data) ? $data : [];
}

function saveState(string $stateFile, array $state): void {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
}

function openLogFile(string $path) {
    $fp = fopen($path, 'r');
    if (!$fp) {
        echo "Error: cannot open {$path}\n";
        exit(1);
    }
    // Seek to end by default? No, we keep offset
    return $fp;
}

function getFileInode(string $path): int {
    $st = @stat($path);
    return is_array($st) && isset($st['ino']) ? (int)$st['ino'] : 0;
}

function seekSafe($fp, int $offset): void {
    if ($offset > 0) {
        fseek($fp, $offset);
    } else {
        fseek($fp, 0);
    }
}

function drainSpool(string $spoolDir, string $apiUrl): void {
    $files = glob($spoolDir . '/failed_*.json');
    if (!$files) {
        return;
    }

    sort($files);
    foreach ($files as $file) {
        $payload = file_get_contents($file);
        if (!$payload) {
            @unlink($file);
            continue;
        }

        $ok = postJson($apiUrl, $payload, 10);
        if ($ok) {
            @unlink($file);
        } else {
            // Stop here to avoid hammering a struggling API
            usleep(500000);
            return;
        }
    }
}

function flushBatch(array $batch, string $apiUrl, string $spoolDir): void {
    if (empty($batch)) {
        return;
    }

    $payload = json_encode([
        'version' => 3,
        'logs' => $batch,
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return;
    }

    $ok = postJson($apiUrl, $payload, 15);
    if ($ok) {
        echo "Sent batch: " . count($batch) . "\n";
        return;
    }

    // Spool failure
    $name = $spoolDir . '/failed_' . date('Ymd_His') . '_' . substr((string)microtime(true), -6) . '.json';
    file_put_contents($name, $payload);
    echo "Spooling failed batch: " . count($batch) . "\n";
}

function postJson(string $url, string $jsonPayload, int $timeoutSeconds): bool {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Connection: keep-alive',
    ]);

    $output = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    return false;
}

/**
 * Your existing parser, unchanged except small hardening
 */
function processLogLine(string $content, int $domain_id): ?array {
    if (!preg_match('/postfix\/(\w+)\[\d+\]/', $content, $serviceMatch)) {
        return null;
    }

    $service = $serviceMatch[1];

    $logTimestamp = '';
    if (preg_match('/^(\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $content, $match)) {
        $currentYear = (int)date('Y');
        $dateTime = DateTime::createFromFormat('M d H:i:s', $match[1]);
        if ($dateTime) {
            $dateTime->setDate($currentYear, (int)$dateTime->format('m'), (int)$dateTime->format('d'));
            if ($dateTime->getTimestamp() > time()) {
                $dateTime->setDate($currentYear - 1, (int)$dateTime->format('m'), (int)$dateTime->format('d'));
            }
            $logTimestamp = $dateTime->format('Y-m-d H:i:s');
        }
    }
    if ($logTimestamp === '') {
        $logTimestamp = date('Y-m-d H:i:s');
    }

    $queueId = '';
    if (preg_match('/postfix\/\w+\[\d+\]:\s+([A-F0-9]{8,15}):/i', $content, $match)) {
        $queueId = $match[1];
    }

    $email = '';
    $fromEmail = '';
    $status = '';
    $dsn = '';
    $relay = '';
    $reason = '';

    if (preg_match('/to=<([^>]*)>/', $content, $match)) {
        $email = $match[1];
    }
    if (preg_match('/from=<([^>]*)>/', $content, $match)) {
        $fromEmail = $match[1];
    }
    if (preg_match('/status=(\w+)/', $content, $match)) {
        $status = $match[1];
    }
    if (preg_match('/dsn=\s*(\d\.\d\.\d)/', $content, $match)) {
        $dsn = $match[1];
    }
    if (preg_match('/relay=([^,]*),/', $content, $match)) {
        $relay = $match[1];
    }
    if (preg_match('/(said|refused to talk to me):\s*(.*)$/', $content, $match)) {
        $reason = $match[2];
    } elseif (preg_match('/status=\w+\s+\((.+)\)\s*$/', $content, $match)) {
        $reason = $match[1];
    }

    $rawLog = substr(trim($content), 0, 1000);

    return [
        'email' => $email,
        'fromEmail' => $fromEmail,
        'status' => $status,
        'dsn' => $dsn,
        'reason' => $reason,
        'relay' => $relay,
        'domainId' => $domain_id,
        'logDate' => $logTimestamp,
        'queueId' => $queueId,
        'service' => $service,
        'rawLog' => $rawLog,
    ];
}