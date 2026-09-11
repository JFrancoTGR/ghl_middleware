<?php
// =======================================================
// CAPISoft -> GHL Terminal Status Sync (CHILPANCINGO) Cron Job [V2 - batched]
// - proyecto_id = 5 (CHILPANCINGO)
// - since_date filter by CAPISoft created_at
// - DRY RUN default = 1 (no writes to GHL)
// - BATCH MODE: process by blocks with checkpoint + request budget to avoid 429
// - SHARED LOCK with stage cron to avoid overlapping writes
// - MATCHING: normalized email(s), fallback phone, fallback opp search by contactId + pipelineId
// - TERMINAL ONLY: 64 => won, 65 => lost, 66 => abandoned
// - UPDATES ONLY: opportunity STATUS (never pipelineStageId / owner / cf)
// =======================================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// ====== CONFIG (hardcoded for Hostinger cron) ======
$ALLOW_CLI_OVERRIDES       = false;
$defaultProyectoId         = 5;
$defaultSinceDate          = '2025-12-01';
$defaultDryRun             = false;
$defaultBatchSize          = 30;
$defaultMaxGhlRequests     = 40;
$defaultStartOver          = false;
$defaultApiTrace           = false;
$defaultApiTraceBodies     = false;
$defaultApiTraceMaxChars   = 500;
$defaultResolutionTrace    = false;
$defaultResolutionMaxChars = 800;
$defaultLogMode            = 'normal'; // normal | debug

$CAPISOFT_BASE  = "https://api-3.capisoftware.com.mx/eu/capi-b/public/api/v2/ventas/oportunidades";
$CAPISOFT_TOKEN = getenv('CAPISOFT_TOKEN') ?: 'CAPISOFT_TOKEN';

$GHL_BASE_URL = "https://services.leadconnectorhq.com";
$GHL_API_VER  = "2021-07-28";
$GHL_TOKEN    = getenv('GHL_TOKEN') ?: 'GHL_TOKEN';

$GHL_LOCATION_ID = "2cOAVW7auz2agTWyCnxF";
$GHL_PIPELINE_ID = "mBsz4BC5Yw9yVf9cfdZj"; // CHILPANCINGO - Flujo de venta

$CAPI_TO_GHL_TERMINAL_STATUS = [
    64 => 'won',
    65 => 'lost',
    66 => 'abandoned',
];

$GLOBALS['api_calls_contacts_search'] = 0;
$GLOBALS['api_calls_contacts_get']    = 0;
$GLOBALS['api_calls_opps_search']     = 0;
$GLOBALS['api_calls_opps_get']        = 0;
$GLOBALS['api_calls_put_opp']         = 0;
$GLOBALS['api_calls_put_contact']     = 0;
$GLOBALS['api_trace_enabled']         = false;
$GLOBALS['api_trace_bodies_enabled']  = false;
$GLOBALS['api_trace_max_chars']       = 500;
$GLOBALS['api_trace_log_file']        = null;
$GLOBALS['resolution_trace_enabled']  = false;
$GLOBALS['resolution_trace_max_chars']= 800;

function get_arg($key)
{
    global $argv;
    if (!is_array($argv)) {
        return null;
    }
    foreach ($argv as $a) {
        if (strpos($a, "--{$key}=") === 0) {
            return substr($a, strlen($key) + 3);
        }
    }
    return null;
}

function get_bool_arg($key, $default = false)
{
    $v = get_arg($key);
    if ($v === null) {
        return $default;
    }
    $v = strtolower(trim((string) $v));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
}

function get_int_arg($key, $default = 0)
{
    $v = get_arg($key);
    if ($v === null || $v === '') {
        return (int) $default;
    }
    return (int) $v;
}

function normalize_log_mode($value, $default = 'normal')
{
    $value = strtolower(trim((string) $value));
    if (!in_array($value, ['normal', 'debug'], true)) {
        return $default;
    }
    return $value;
}

function is_debug_log_mode()
{
    return (($GLOBALS['log_mode'] ?? 'normal') === 'debug');
}


function log_line($file, $line)
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
}

function one_line($value, $maxChars = 800)
{
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $value = (string) $value;
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);
    if ($maxChars > 0 && strlen($value) > $maxChars) {
        $value = substr($value, 0, $maxChars) . '...';
    }
    return $value;
}

function log_resolution($file, array $data)
{
    if (empty($GLOBALS['resolution_trace_enabled']) || !is_debug_log_mode()) {
        return;
    }
    $max = (int) ($GLOBALS['resolution_trace_max_chars'] ?? 800);
    log_line($file, date('c') . " RESOLUTION " . one_line($data, $max));
}

function extract_attempt_http(array $lookup, $channel)
{
    $attempts = $lookup['attempts'] ?? [];
    if (!is_array($attempts)) {
        return null;
    }
    $last = null;
    foreach ($attempts as $attempt) {
        if (($attempt['channel'] ?? null) === $channel) {
            $last = $attempt['http'] ?? null;
        }
    }
    return $last;
}

function extract_attempt_count(array $lookup, $channel)
{
    $attempts = $lookup['attempts'] ?? [];
    if (!is_array($attempts)) {
        return null;
    }
    $last = null;
    foreach ($attempts as $attempt) {
        if (($attempt['channel'] ?? null) === $channel) {
            $last = $attempt['count'] ?? null;
        }
    }
    return $last;
}

function to_ts($dt)
{
    if (!$dt) {
        return null;
    }
    $ts = strtotime($dt);
    return ($ts === false) ? null : $ts;
}

function read_json_file($path, $default)
{
    if (!file_exists($path)) {
        return $default;
    }
    $json = json_decode(file_get_contents($path), true);
    return is_array($json) ? $json : $default;
}

function write_json_file($path, $data)
{
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function read_state($path)
{
    return read_json_file($path, ['last_run_at' => null, 'by_clave' => [], 'meta' => []]);
}

function write_state($path, $state)
{
    write_json_file($path, $state);
}

function read_progress($path)
{
    return read_json_file($path, [
        'next_index'       => 0,
        'last_clave'       => null,
        'last_run_at'      => null,
        'last_stop_reason' => null,
        'full_passes'      => 0,
    ]);
}

function write_progress($path, $progress)
{
    write_json_file($path, $progress);
}

function http_json($method, $url, $headers, $payload = null, $timeout = 25)
{
    $traceEnabled = !empty($GLOBALS['api_trace_enabled']);
    $traceBodies  = !empty($GLOBALS['api_trace_bodies_enabled']);
    $traceMax     = (int) ($GLOBALS['api_trace_max_chars'] ?? 500);
    $traceFile    = $GLOBALS['api_trace_log_file'] ?? null;

    $started = microtime(true);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $requestBody = null;
    if ($payload !== null) {
        $requestBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
    }

    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $elapsedMs = (int) round((microtime(true) - $started) * 1000);

    if ($traceEnabled && $traceFile) {
        $line = date('c') . " API_CALL method={$method} url={$url} http={$http} ms={$elapsedMs}";
        if ($err !== '') {
            $line .= " curl_err=" . str_replace(["\n", "\r"], ' ', $err);
        }
        if ($traceBodies) {
            if ($requestBody !== null) {
                $req = substr(str_replace(["\n", "\r"], ' ', $requestBody), 0, $traceMax);
                $line .= " req={$req}";
            }
            if ($body !== false && $body !== null && $body !== '') {
                $resp = substr(str_replace(["\n", "\r"], ' ', (string) $body), 0, $traceMax);
                $line .= " resp={$resp}";
            }
        }
        log_line($traceFile, $line);
    }

    return [$http, $body, $err];
}

function capi_fetch_opps($url, $token)
{
    $headers = ["Accept: application/json"];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }
    return http_json('GET', $url, $headers, null, 25);
}

function ghl_headers($token, $version)
{
    return [
        "Authorization: Bearer {$token}",
        "Version: {$version}",
        "Accept: application/json",
        "Content-Type: application/json",
    ];
}

function ghl_requests_used()
{
    return
        (int) $GLOBALS['api_calls_contacts_search'] +
        (int) $GLOBALS['api_calls_contacts_get'] +
        (int) $GLOBALS['api_calls_opps_search'] +
        (int) $GLOBALS['api_calls_opps_get'] +
        (int) $GLOBALS['api_calls_put_opp'] +
        (int) $GLOBALS['api_calls_put_contact'];
}

function normalize_phone($phone)
{
    if (!$phone) {
        return null;
    }
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return null;
    }
    if (strlen($digits) > 10 && substr($digits, 0, 2) === '52') {
        return $digits;
    }
    if (strlen($digits) === 10) {
        return '52' . $digits;
    }
    return $digits;
}

function normalize_email($email)
{
    if (!$email || !is_string($email)) {
        return null;
    }
    $email = strtolower(trim($email));
    return $email === '' ? null : $email;
}

function email_candidates($raw)
{
    if (!$raw || !is_string($raw)) {
        return [];
    }
    $parts = preg_split('/[;,]+/', $raw);
    $out = [];
    foreach ($parts as $part) {
        $e = normalize_email($part);
        if ($e) {
            $out[$e] = true;
        }
    }
    return array_keys($out);
}

function is_rate_limit_response($http, $body)
{
    if ((int) $http === 429) {
        return true;
    }
    $msg = '';
    if (is_string($body) && $body !== '') {
        $json = json_decode($body, true);
        if (is_array($json) && !empty($json['message'])) {
            $msg = strtolower((string) $json['message']);
        } else {
            $msg = strtolower($body);
        }
    }
    return strpos($msg, 'too many requests') !== false || strpos($msg, 'rate limit') !== false;
}

function ghl_search_contact($ghlBase, $headers, $locationId, array $filters, $logFile = null, $clave = null, $tag = 'CONTACT_SEARCH')
{
    $payload = [
        'locationId' => $locationId,
        'page'       => 1,
        'pageLimit'  => 10,
        'filters'    => $filters,
    ];

    $field = $filters[0]['field'] ?? null;
    $value = $filters[0]['value'] ?? null;

    $GLOBALS['api_calls_contacts_search']++;
    [$http, $body, $err] = http_json('POST', $ghlBase . '/contacts/search', $headers, $payload, 25);

    if (is_rate_limit_response($http, $body)) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " RATE_LIMIT {$tag} clave={$clave} http={$http} body={$snippet}");
        }
        return [null, ['reason' => 'rate_limit', 'http' => $http, 'err' => $err, 'field' => $field, 'value' => $value, 'count' => null]];
    }

    if ($err || $http >= 400) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " ERROR {$tag} clave={$clave} http={$http} err={$err} body={$snippet}");
        }
        return [null, ['reason' => 'http_error', 'http' => $http, 'err' => $err, 'field' => $field, 'value' => $value, 'count' => null]];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " ERROR {$tag}_PARSE clave={$clave} http={$http} body={$snippet}");
        }
        return [null, ['reason' => 'parse_error', 'http' => $http, 'field' => $field, 'value' => $value, 'count' => null]];
    }

    $contacts = $json['contacts'] ?? null;
    $count = is_array($contacts) ? count($contacts) : 0;
    if (!is_array($contacts) || $count === 0) {
        return [null, ['reason' => 'empty', 'http' => $http, 'field' => $field, 'value' => $value, 'count' => 0]];
    }

    return [$contacts[0], ['reason' => 'ok', 'http' => $http, 'field' => $field, 'value' => $value, 'count' => $count]];
}

function ghl_get_contact_by_id($ghlBase, $headers, $contactId, $logFile = null, $clave = null)
{
    $GLOBALS['api_calls_contacts_get']++;
    [$http, $body, $err] = http_json('GET', $ghlBase . '/contacts/' . urlencode($contactId), $headers, null, 25);

    if (is_rate_limit_response($http, $body)) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " RATE_LIMIT GHL_CONTACT_GET clave={$clave} contact={$contactId} http={$http} body={$snippet}");
        }
        return [null, ['reason' => 'rate_limit', 'http' => $http, 'err' => $err]];
    }

    if ($err || $http >= 400) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " ERROR GHL_CONTACT_GET clave={$clave} contact={$contactId} http={$http} err={$err} body={$snippet}");
        }
        return [null, ['reason' => 'http_error', 'http' => $http, 'err' => $err]];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [null, ['reason' => 'parse_error', 'http' => $http]];
    }

    $contact = $json['contact'] ?? ($json['data']['contact'] ?? ($json['data'] ?? $json));
    if (!is_array($contact) || empty($contact['id'])) {
        return [null, ['reason' => 'empty', 'http' => $http]];
    }

    return [$contact, ['reason' => 'ok', 'http' => $http]];
}

function ghl_find_opp_by_contact_pipeline($ghlBase, $headers, $locationId, $pipelineId, $contactId, $logFile = null, $clave = null)
{
    [$contact, $meta] = ghl_get_contact_by_id($ghlBase, $headers, $contactId, $logFile, $clave);
    if (!is_array($contact) || empty($contact['id'])) {
        return [null, $meta];
    }

    $opps = $contact['opportunities'] ?? [];
    if (is_array($opps)) {
        foreach ($opps as $opp) {
            $oppPipelineId = $opp['pipelineId'] ?? null;
            if ($oppPipelineId === $pipelineId && !empty($opp['id'])) {
                return [$opp, ['reason' => 'ok', 'http' => $meta['http'] ?? 200, 'source' => 'contact_get']];
            }
        }
    }

    return [null, ['reason' => 'empty', 'http' => $meta['http'] ?? 200, 'source' => 'contact_get', 'count' => 0]];
}

function ghl_find_contact_and_opp($ghlBase, $headers, $locationId, $pipelineId, $rawEmail, $phone, $logFile = null, $clave = null)
{
    $contact = null;
    $meta    = [
        'reason' => 'no_identifier',
        'lookup' => [
            'emails_tried' => [],
            'phone_tried' => null,
            'attempts' => [],
            'matched_by' => null,
            'matched_value' => null,
        ],
    ];

    $emails = email_candidates($rawEmail);
    foreach ($emails as $email) {
        $meta['lookup']['emails_tried'][] = $email;
        [$contact, $searchMeta] = ghl_search_contact(
            $ghlBase,
            $headers,
            $locationId,
            [['field' => 'email', 'operator' => 'eq', 'value' => $email]],
            $logFile,
            $clave,
            'GHL_CONTACT_SEARCH_EMAIL'
        );

        $meta['lookup']['attempts'][] = [
            'channel' => 'email',
            'value'   => $email,
            'http'    => $searchMeta['http'] ?? null,
            'reason'  => $searchMeta['reason'] ?? null,
            'count'   => $searchMeta['count'] ?? null,
        ];

        if ($contact) {
            $meta = array_merge($meta, $searchMeta);
            $meta['matched_by'] = 'email';
            $meta['matched_value'] = $email;
            $meta['lookup']['matched_by'] = 'email';
            $meta['lookup']['matched_value'] = $email;
            break;
        }

        if (($searchMeta['reason'] ?? null) === 'rate_limit' || ($searchMeta['reason'] ?? null) === 'http_error') {
            $meta = array_merge($meta, $searchMeta);
            return [null, null, $meta];
        }

        $meta = array_merge($meta, $searchMeta);
    }

    if (!$contact && $phone) {
        $meta['lookup']['phone_tried'] = $phone;
        [$contact, $searchMeta] = ghl_search_contact(
            $ghlBase,
            $headers,
            $locationId,
            [['field' => 'phone', 'operator' => 'eq', 'value' => $phone]],
            $logFile,
            $clave,
            'GHL_CONTACT_SEARCH_PHONE'
        );

        $meta['lookup']['attempts'][] = [
            'channel' => 'phone',
            'value'   => $phone,
            'http'    => $searchMeta['http'] ?? null,
            'reason'  => $searchMeta['reason'] ?? null,
            'count'   => $searchMeta['count'] ?? null,
        ];

        if ($contact) {
            $meta = array_merge($meta, $searchMeta);
            $meta['matched_by'] = 'phone';
            $meta['matched_value'] = $phone;
            $meta['lookup']['matched_by'] = 'phone';
            $meta['lookup']['matched_value'] = $phone;
        } else {
            $meta = array_merge($meta, $searchMeta);
        }
    }

    if (!$contact) {
        if (!empty($meta['lookup']['attempts'])) {
            $meta['reason'] = ($meta['reason'] ?? 'empty');
        }
        return [null, null, $meta];
    }

    $opps     = $contact['opportunities'] ?? [];
    $foundOpp = null;
    if (is_array($opps)) {
        foreach ($opps as $opp) {
            if (($opp['pipelineId'] ?? null) === $pipelineId) {
                $foundOpp = $opp;
                break;
            }
        }
    }

    return [$contact, $foundOpp, $meta];
}

function ghl_update_opportunity_status($ghlBase, $headers, $opportunityId, $status)
{
    $url = $ghlBase . '/opportunities/' . urlencode($opportunityId);
    $payload = ['status' => $status];
    $GLOBALS['api_calls_put_opp']++;
    return http_json('PUT', $url, $headers, $payload, 25);
}

// ====== INPUTS ======
$proyectoId         = $defaultProyectoId;
$sinceDate          = $defaultSinceDate;
$dryRun             = $defaultDryRun;
$batchSize          = $defaultBatchSize;
$maxGhlRequests     = $defaultMaxGhlRequests;
$startOver          = $defaultStartOver;
$apiTrace           = $defaultApiTrace;
$apiTraceBodies     = $defaultApiTraceBodies;
$apiTraceMaxChars   = $defaultApiTraceMaxChars;
$resolutionTrace    = $defaultResolutionTrace;
$resolutionMaxChars = $defaultResolutionMaxChars;

$p = get_arg('proyecto_id');
$s = get_arg('since_date');
if ($p !== null) { $proyectoId = (int) $p; }
if ($s !== null) { $sinceDate = (string) $s; }
$dryRun             = get_bool_arg('dry_run', $defaultDryRun);
$batchSize          = max(1, get_int_arg('batch_size', $defaultBatchSize));
$maxGhlRequests     = max(1, get_int_arg('max_ghl_requests', $defaultMaxGhlRequests));
$startOver          = get_bool_arg('start_over', $defaultStartOver);
$apiTrace           = get_bool_arg('api_trace', $defaultApiTrace);
$apiTraceBodies     = get_bool_arg('api_trace_bodies', $defaultApiTraceBodies);
$apiTraceMaxChars   = max(100, get_int_arg('api_trace_max_chars', $defaultApiTraceMaxChars));
$resolutionTrace    = get_bool_arg('resolution_trace', $defaultResolutionTrace);
$resolutionMaxChars = max(200, get_int_arg('resolution_max_chars', $defaultResolutionMaxChars));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceDate)) {
    echo json_encode(['ok' => false, 'error' => 'since_date inválido, usa YYYY-MM-DD']);
    exit(1);
}
if ($proyectoId !== 5) {
    echo json_encode(['ok' => false, 'error' => 'Este script está fijo para CHILPANCINGO (proyecto_id=5).']);
    exit(1);
}
if (!$GHL_TOKEN || $GHL_TOKEN === 'GHL_TOKEN' || $GHL_TOKEN === 'MI_TOKEN_GHL') {
    echo json_encode(['ok' => false, 'error' => 'Configura GHL_TOKEN real vía env.']);
    exit(1);
}

$storageDir = __DIR__ . '/storage';
$logsDir    = __DIR__ . '/logs';
if (!is_dir($storageDir)) { mkdir($storageDir, 0755, true); }
if (!is_dir($logsDir))    { mkdir($logsDir, 0755, true); }

$stateFile    = $storageDir . '/capisoft_state_terminal_project_' . $proyectoId . '_productive.json';
$progressFile = $storageDir . '/capisoft_progress_terminal_project_' . $proyectoId . '_productive.json';
$logFile      = $logsDir . '/capisoft_terminal_status_chilpancingo_productive.log';
$lockFile     = $storageDir . '/capisoft_shared_project_' . $proyectoId . '.lock'; // shared with stage cron
$lockSkipLog  = $logsDir . '/terminal_lock_skips.log';

$GLOBALS['api_trace_enabled']         = $apiTrace;
$GLOBALS['api_trace_bodies_enabled']  = $apiTraceBodies;
$GLOBALS['api_trace_max_chars']       = $apiTraceMaxChars;
$GLOBALS['api_trace_log_file']        = $logFile;
$GLOBALS['resolution_trace_enabled']  = $resolutionTrace;
$GLOBALS['resolution_trace_max_chars']= $resolutionMaxChars;

// Terminal status has lower priority than stages.
// Give the stage cron a short head start when Hostinger wakes both scripts in the same window.
// This avoids terminal_status acquiring the shared lock first and causing stage LOCK_BUSY skips.
$terminalInitialYieldSeconds = 12;
if ($terminalInitialYieldSeconds > 0) {
    sleep($terminalInitialYieldSeconds);
}

// If stages is using the shared project lock, retry briefly instead of skipping immediately.
$lockRetryAttempts = 6;
$lockSleepSeconds  = 10;
$lockHandle        = null;
$lockAcquired      = false;
$lockAttempt       = 0;
$totalLockAttempts = $lockRetryAttempts + 1; // initial attempt + retries

for ($attempt = 1; $attempt <= $totalLockAttempts; $attempt++) {
    $lockAttempt = $attempt;
    $candidateHandle = fopen($lockFile, 'c');

    if ($candidateHandle && flock($candidateHandle, LOCK_EX | LOCK_NB)) {
        $lockHandle   = $candidateHandle;
        $lockAcquired = true;
        break;
    }

    if (is_resource($candidateHandle)) {
        fclose($candidateHandle);
    }

    if ($attempt <= $lockRetryAttempts) {
        sleep($lockSleepSeconds);
    }
}

if (!$lockAcquired || !is_resource($lockHandle)) {
    log_line(
        $lockSkipLog,
        date('c')
        . ' LOCK_BUSY proyecto_id=' . $proyectoId
        . ' attempts=' . $lockAttempt
        . ' retries=' . $lockRetryAttempts
        . ' waited_seconds=' . ($lockRetryAttempts * $lockSleepSeconds)
    );
    exit(0);
}

if ($lockAttempt > 1) {
    log_line(
        $lockSkipLog,
        date('c')
        . ' LOCK_RETRY_ACQUIRED proyecto_id=' . $proyectoId
        . ' attempts=' . $lockAttempt
        . ' waited_seconds=' . (($lockAttempt - 1) * $lockSleepSeconds)
    );
}
$startedAt  = microtime(true);
$ghlHeaders = ghl_headers($GHL_TOKEN, $GHL_API_VER);

$state = read_state($stateFile);
if (!isset($state['by_clave']) || !is_array($state['by_clave'])) {
    $state['by_clave'] = [];
}
$progress = read_progress($progressFile);
if ($startOver) {
    $progress = [
        'next_index'       => 0,
        'last_clave'       => null,
        'last_run_at'      => null,
        'last_stop_reason' => 'start_over',
        'full_passes'      => (int) ($progress['full_passes'] ?? 0),
    ];
}

$beforeCount = count($state['by_clave']);
log_line($logFile, date('c') . ' RUN_START log_mode=' . $logMode . ' dry_run=' . ($dryRun ? 1 : 0) . " since_date={$sinceDate} batch_size={$batchSize} max_ghl_requests={$maxGhlRequests} start_over=" . ($startOver ? 1 : 0));

$CAPISOFT_URL = $CAPISOFT_BASE . '?proyecto_id=' . $proyectoId;
[$http, $body, $err] = capi_fetch_opps($CAPISOFT_URL, $CAPISOFT_TOKEN);
if ($err || $http >= 400) {
    log_line($logFile, date('c') . " ERROR CAPI GET proyecto_id={$proyectoId} http={$http} err={$err}");
    flock($lockHandle, LOCK_UN); fclose($lockHandle);
    echo json_encode(['ok' => false, 'http' => $http, 'error' => $err ?: 'CAPISoft error']);
    exit(1);
}

$json = json_decode($body, true);
$data = $json['data'] ?? null;
if (!is_array($data)) {
    log_line($logFile, date('c') . " ERROR CAPI PARSE proyecto_id={$proyectoId} body_unexpected");
    flock($lockHandle, LOCK_UN); fclose($lockHandle);
    echo json_encode(['ok' => false, 'error' => 'Respuesta inesperada de CAPISoft']);
    exit(1);
}

usort($data, function ($a, $b) {
    return (int) ($a['clave'] ?? 0) <=> (int) ($b['clave'] ?? 0);
});

$sinceTs = to_ts($sinceDate . ' 00:00:00');
$filtered = [];
foreach ($data as $o) {
    $createdTs = to_ts($o['created_at'] ?? null);
    if ($createdTs === null) { continue; }
    if ($createdTs >= $sinceTs) { $filtered[] = $o; }
}

$totalFiltered = count($filtered);
$startIndex = (int) ($progress['next_index'] ?? 0);
if ($startIndex < 0 || $startIndex >= $totalFiltered) {
    $startIndex = 0;
}

$changesFound = 0;
$updatesDone = 0;
$wouldUpdateCount = 0;
$skippedNoMap = 0;
$skippedNoContact = 0;
$skippedNoOpp = 0;
$skippedAligned = 0;
$deferredRateLimit = 0;
$deferredLookupHttpError = 0;
$errorsGhl = 0;
$itemsProcessed = 0;
$lastProcessedIndex = null;
$lastProcessedClave = null;
$stopReason = 'completed_batch';
$rateLimitMeta = null;
$actions = [
    'status_applied' => 0,
    'status_would'   => 0,
];

for ($idx = $startIndex; $idx < $totalFiltered; $idx++) {
    if ($itemsProcessed >= $batchSize) {
        $stopReason = 'batch_limit';
        break;
    }
    if (ghl_requests_used() >= $maxGhlRequests) {
        $stopReason = 'request_budget';
        break;
    }

    $o = $filtered[$idx];
    $clave = (string) ($o['clave'] ?? '');
    if ($clave === '') {
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " SKIP EMPTY_CLAVE idx={$idx}");
        }
        $itemsProcessed++;
        $lastProcessedIndex = $idx;
        continue;
    }

    $current = [
        'etapa_id'       => $o['etapa_id'] ?? null,
        'etapa'          => $o['etapa'] ?? null,
        'updated_by'     => $o['updated_by'] ?? null,
        'created_at'     => $o['created_at'] ?? null,
        'updated_at'     => $o['updated_at'] ?? ($o['created_at'] ?? null),
        'responsable'    => $o['responsable'] ?? null,
        'responsable_id' => $o['responsable_id'] ?? null,
        'emails'         => $o['emails'] ?? null,
        'telefonos'      => $o['telefonos'] ?? null,
        'id'             => $o['id'] ?? null,
    ];

    $isFirstSeen = !isset($state['by_clave'][$clave]);
    if ($isFirstSeen) {
        $state['by_clave'][$clave] = $current;
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " SEED FIRST_SEEN clave={$clave} etapa_id=" . ($current['etapa_id'] ?? '') . " etapa=\"" . ($current['etapa'] ?? '') . "\" email=" . one_line($current['emails'] ?? '', 120) . " phone=" . one_line($current['telefonos'] ?? '', 60));
        }
    }

    $capEtapaId = (int) ($current['etapa_id'] ?? 0);
    $targetStatus = $CAPI_TO_GHL_TERMINAL_STATUS[$capEtapaId] ?? null;

    if (!$targetStatus) {
        $skippedNoMap++;
        $changesFound++;
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " SKIP NO_MAP clave={$clave} etapa_id={$capEtapaId} etapa=\"" . ($current['etapa'] ?? '') . "\" decision=skip_not_terminal_stage");
        }
        log_resolution($logFile, [
            'clave' => $clave,
            'email_original' => $current['emails'] ?? null,
            'emails_tried' => [],
            'phone_original' => $current['telefonos'] ?? null,
            'phone_tried' => null,
            'email_search_http' => null,
            'email_search_count' => null,
            'phone_search_http' => null,
            'phone_search_count' => null,
            'contact_id' => null,
            'opp_id' => null,
            'matched_by' => null,
            'matched_value' => null,
            'decision' => 'skip_not_terminal_stage',
            'first_seen' => $isFirstSeen,
        ]);
        $state['by_clave'][$clave] = $current;
        $itemsProcessed++;
        $lastProcessedIndex = $idx;
        $lastProcessedClave = $clave;
        continue;
    }

    $rawEmail = $current['emails'] ?? null;
    $phone = normalize_phone($current['telefonos'] ?? null);

    [$contact, $opp, $meta] = ghl_find_contact_and_opp($GHL_BASE_URL, $ghlHeaders, $GHL_LOCATION_ID, $GHL_PIPELINE_ID, $rawEmail, $phone, $logFile, $clave);

    $emailSearchHttp = null; $emailSearchCount = null; $phoneSearchHttp = null; $phoneSearchCount = null;
    if (!empty($meta['lookup']['attempts']) && is_array($meta['lookup']['attempts'])) {
        foreach ($meta['lookup']['attempts'] as $att) {
            if (($att['channel'] ?? null) === 'email' && $emailSearchHttp === null) {
                $emailSearchHttp = $att['http'] ?? null;
                $emailSearchCount = $att['count'] ?? null;
            }
            if (($att['channel'] ?? null) === 'phone' && $phoneSearchHttp === null) {
                $phoneSearchHttp = $att['http'] ?? null;
                $phoneSearchCount = $att['count'] ?? null;
            }
        }
    }

    if (!$contact) {
        $reason = $meta['reason'] ?? 'unknown';
        if ($reason === 'rate_limit') {
            $deferredRateLimit++;
            $rateLimitMeta = ['clave' => $clave, 'http' => $meta['http'] ?? null, 'stage' => 'contact_lookup'];
            $stopReason = 'rate_limit_contact_lookup';
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} stage=contact_lookup http=" . ($meta['http'] ?? '') . " email=" . one_line($rawEmail, 120) . " phone=" . ($phone ?? ''));
            log_resolution($logFile, [
                'clave' => $clave,
                'email_original' => $rawEmail,
                'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
                'phone_original' => $current['telefonos'] ?? null,
                'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
                'email_search_http' => $emailSearchHttp,
                'email_search_count' => $emailSearchCount,
                'phone_search_http' => $phoneSearchHttp,
                'phone_search_count' => $phoneSearchCount,
                'contact_id' => null,
                'opp_id' => null,
                'matched_by' => null,
                'matched_value' => null,
                'decision' => 'lookup_rate_limited',
                'first_seen' => $isFirstSeen,
            ]);
            $state['by_clave'][$clave] = $current;
            break;
        }

        if ($reason === 'http_error' || $reason === 'parse_error') {
            $deferredLookupHttpError++;
            log_line($logFile, date('c') . " DEFER LOOKUP_HTTP_ERROR clave={$clave} reason={$reason} http=" . ($meta['http'] ?? '') . " err=" . ($meta['err'] ?? ''));
            log_resolution($logFile, [
                'clave' => $clave,
                'email_original' => $rawEmail,
                'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
                'phone_original' => $current['telefonos'] ?? null,
                'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
                'email_search_http' => $emailSearchHttp,
                'email_search_count' => $emailSearchCount,
                'phone_search_http' => $phoneSearchHttp,
                'phone_search_count' => $phoneSearchCount,
                'contact_id' => null,
                'opp_id' => null,
                'matched_by' => null,
                'matched_value' => null,
                'decision' => 'lookup_http_error',
                'first_seen' => $isFirstSeen,
            ]);
            $state['by_clave'][$clave] = $current;
            $itemsProcessed++;
            $lastProcessedIndex = $idx;
            $lastProcessedClave = $clave;
            continue;
        }

        $skippedNoContact++;
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " SKIP NO_CONTACT clave={$clave} email=" . one_line($rawEmail, 120) . " phone=" . ($phone ?? '') . " decision=skip_no_contact");
        }
        log_resolution($logFile, [
            'clave' => $clave,
            'email_original' => $rawEmail,
            'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
            'phone_original' => $current['telefonos'] ?? null,
            'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
            'email_search_http' => $emailSearchHttp,
            'email_search_count' => $emailSearchCount,
            'phone_search_http' => $phoneSearchHttp,
            'phone_search_count' => $phoneSearchCount,
            'contact_id' => null,
            'opp_id' => null,
            'matched_by' => null,
            'matched_value' => null,
            'decision' => 'skip_no_contact',
            'first_seen' => $isFirstSeen,
        ]);
        $state['by_clave'][$clave] = $current;
        $itemsProcessed++;
        $lastProcessedIndex = $idx;
        $lastProcessedClave = $clave;
        continue;
    }

    $contactId = $contact['id'] ?? null;
    if (!is_array($opp) || empty($opp['id'])) {
        [$opp2, $m2] = ghl_find_opp_by_contact_pipeline($GHL_BASE_URL, $ghlHeaders, $GHL_LOCATION_ID, $GHL_PIPELINE_ID, $contactId, $logFile, $clave);
        if (($m2['reason'] ?? null) === 'rate_limit') {
            $deferredRateLimit++;
            $rateLimitMeta = ['clave' => $clave, 'http' => $m2['http'] ?? null, 'stage' => 'opp_lookup'];
            $stopReason = 'rate_limit_opp_lookup';
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} stage=opp_lookup http=" . ($m2['http'] ?? '') . " contact={$contactId}");
            log_resolution($logFile, [
                'clave' => $clave,
                'email_original' => $rawEmail,
                'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
                'phone_original' => $current['telefonos'] ?? null,
                'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
                'email_search_http' => $emailSearchHttp,
                'email_search_count' => $emailSearchCount,
                'phone_search_http' => $phoneSearchHttp,
                'phone_search_count' => $phoneSearchCount,
                'contact_id' => $contactId,
                'opp_id' => null,
                'matched_by' => $meta['matched_by'] ?? null,
                'matched_value' => $meta['matched_value'] ?? null,
                'decision' => 'lookup_rate_limited',
                'first_seen' => $isFirstSeen,
            ]);
            $state['by_clave'][$clave] = $current;
            break;
        }
        if (!is_array($opp2) || empty($opp2['id'])) {
            $skippedNoOpp++;
            if (is_debug_log_mode()) {
                log_line($logFile, date('c') . " SKIP NO_OPP clave={$clave} contact={$contactId} decision=skip_no_opp");
            }

            if ($resolutionTrace) {
                $lookup = $meta['lookup'] ?? [];
                log_resolution($logFile, [
                    'clave'              => $clave,
                    'email_original'     => $rawEmail,
                    'emails_tried'       => $lookup['emails_tried'] ?? [],
                    'phone_original'     => $current['telefonos'] ?? null,
                    'phone_tried'        => $lookup['phone_tried'] ?? null,
                    'email_search_http'  => extract_attempt_http($lookup, 'email'),
                    'email_search_count' => extract_attempt_count($lookup, 'email'),
                    'phone_search_http'  => extract_attempt_http($lookup, 'phone'),
                    'phone_search_count' => extract_attempt_count($lookup, 'phone'),
                    'contact_id'         => $contactId,
                    'opp_id'             => null,
                    'matched_by'         => $meta['matched_by'] ?? ($lookup['matched_by'] ?? null),
                    'matched_value'      => $meta['matched_value'] ?? ($lookup['matched_value'] ?? null),
                    'decision'           => 'skip_no_opp',
                    'first_seen'         => $isFirstSeen,
                ]);
            }

            $idx++;
            $itemsProcessed++;
            continue;
        }
        $opp = $opp2;
    }

    $oppId = $opp['id'] ?? null;
    $currentStatus = $opp['status'] ?? null;
    if ($currentStatus === $targetStatus) {
        $skippedAligned++;
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " SKIP ALIGNED clave={$clave} opp={$oppId} status={$currentStatus}");
        }
        log_resolution($logFile, [
            'clave' => $clave,
            'email_original' => $rawEmail,
            'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
            'phone_original' => $current['telefonos'] ?? null,
            'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
            'email_search_http' => $emailSearchHttp,
            'email_search_count' => $emailSearchCount,
            'phone_search_http' => $phoneSearchHttp,
            'phone_search_count' => $phoneSearchCount,
            'contact_id' => $contactId,
            'opp_id' => $oppId,
            'matched_by' => $meta['matched_by'] ?? null,
            'matched_value' => $meta['matched_value'] ?? null,
            'decision' => 'aligned',
            'first_seen' => $isFirstSeen,
        ]);
        $state['by_clave'][$clave] = $current;
        $itemsProcessed++;
        $lastProcessedIndex = $idx;
        $lastProcessedClave = $clave;
        continue;
    }

    if ($dryRun) {
        $wouldUpdateCount++;
        $actions['status_would']++;
        if (is_debug_log_mode()) {
            log_line($logFile, date('c') . " DRY GHL_OPP_STATUS_UPDATE clave={$clave} opp={$oppId} status={$targetStatus} (from {$currentStatus})");
        }
        log_resolution($logFile, [
            'clave' => $clave,
            'email_original' => $rawEmail,
            'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
            'phone_original' => $current['telefonos'] ?? null,
            'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
            'email_search_http' => $emailSearchHttp,
            'email_search_count' => $emailSearchCount,
            'phone_search_http' => $phoneSearchHttp,
            'phone_search_count' => $phoneSearchCount,
            'contact_id' => $contactId,
            'opp_id' => $oppId,
            'matched_by' => $meta['matched_by'] ?? null,
            'matched_value' => $meta['matched_value'] ?? null,
            'decision' => 'status_would_apply',
            'from_status' => $currentStatus,
            'to_status' => $targetStatus,
            'first_seen' => $isFirstSeen,
        ]);
    } else {
        [$sh, $sb, $se] = ghl_update_opportunity_status($GHL_BASE_URL, $ghlHeaders, $oppId, $targetStatus);
        if (is_rate_limit_response($sh, $sb)) {
            $deferredRateLimit++;
            $rateLimitMeta = ['clave' => $clave, 'http' => $sh, 'stage' => 'opp_status_update'];
            $stopReason = 'rate_limit_opp_status_update';
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} stage=opp_status_update opp={$oppId} http={$sh}");
            $state['by_clave'][$clave] = $current;
            break;
        }
        if ($se || $sh >= 400) {
            $errorsGhl++;
            log_line($logFile, date('c') . " ERROR GHL_OPP_STATUS_UPDATE clave={$clave} opp={$oppId} http={$sh} err={$se} body=" . substr((string) $sb, 0, 500));
        } else {
            $updatesDone++;
            $actions['status_applied']++;
            log_line($logFile, date('c') . " OK GHL_OPP_STATUS_UPDATE clave={$clave} opp={$oppId} status={$targetStatus} (from {$currentStatus})");
            log_resolution($logFile, [
                'clave' => $clave,
                'email_original' => $rawEmail,
                'emails_tried' => $meta['lookup']['emails_tried'] ?? [],
                'phone_original' => $current['telefonos'] ?? null,
                'phone_tried' => $meta['lookup']['phone_tried'] ?? null,
                'email_search_http' => $emailSearchHttp,
                'email_search_count' => $emailSearchCount,
                'phone_search_http' => $phoneSearchHttp,
                'phone_search_count' => $phoneSearchCount,
                'contact_id' => $contactId,
                'opp_id' => $oppId,
                'matched_by' => $meta['matched_by'] ?? null,
                'matched_value' => $meta['matched_value'] ?? null,
                'decision' => 'status_applied',
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'first_seen' => $isFirstSeen,
            ]);
        }
    }

    $changesFound++;
    $state['by_clave'][$clave] = $current;
    $itemsProcessed++;
    $lastProcessedIndex = $idx;
    $lastProcessedClave = $clave;
}

$nextIndex = 0;
if ($stopReason === 'completed_batch') {
    $nextIndex = 0;
} elseif ($stopReason === 'batch_limit' || $stopReason === 'request_budget') {
    $nextIndex = ($lastProcessedIndex === null) ? $startIndex : ($lastProcessedIndex + 1);
} elseif (strpos((string) $stopReason, 'rate_limit_') === 0) {
    $nextIndex = ($lastProcessedIndex === null) ? $startIndex : ($lastProcessedIndex + 1);
}
if ($nextIndex >= $totalFiltered) {
    $nextIndex = 0;
    $stopReason = 'completed_batch';
}

$state['last_run_at'] = date('c');
$state['meta'] = [
    'dry_run_last'          => $dryRun,
    'version'               => 'terminal_v2_batched',
    'batch_size_last'       => $batchSize,
    'max_ghl_requests_last' => $maxGhlRequests,
    'last_stop_reason'      => $stopReason,
    'items_processed_last'  => $itemsProcessed,
    'ghl_requests_used_last'=> ghl_requests_used(),
];
write_state($stateFile, $state);

$progress['next_index'] = $nextIndex;
$progress['last_clave'] = $lastProcessedClave;
$progress['last_run_at'] = date('c');
$progress['last_stop_reason'] = $stopReason;
if ($stopReason === 'completed_batch') {
    $progress['full_passes'] = (int) ($progress['full_passes'] ?? 0) + 1;
}
write_progress($progressFile, $progress);

$afterCount = count($state['by_clave']);
$elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
log_line($logFile, date('c') . " RUN_END log_mode={$logMode} stop_reason={$stopReason} next_index={$nextIndex} last_clave=" . ($lastProcessedClave ?? '') . " items_processed={$itemsProcessed} state_before={$beforeCount} state_after={$afterCount} changes_found={$changesFound} updates_done={$updatesDone} would_update={$wouldUpdateCount} skipped_no_map={$skippedNoMap} skipped_no_contact={$skippedNoContact} skipped_no_opp={$skippedNoOpp} skipped_aligned={$skippedAligned} deferred_rate_limit={$deferredRateLimit} deferred_lookup_http_error={$deferredLookupHttpError} errors_ghl={$errorsGhl} ghl_requests=" . ghl_requests_used() . " elapsed_ms={$elapsedMs}");

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

echo json_encode([
    'ok' => true,
    'dry_run' => $dryRun,
    'proyecto_id' => $proyectoId,
    'since_date' => $sinceDate,
    'batch_size' => $batchSize,
    'max_ghl_requests' => $maxGhlRequests,
    'start_index' => $startIndex,
    'next_index' => $nextIndex,
    'last_processed_index' => $lastProcessedIndex,
    'last_processed_clave' => $lastProcessedClave,
    'stop_reason' => $stopReason,
    'full_pass_completed' => ($stopReason === 'completed_batch'),
    'total_capisoft' => count($data),
    'observed_created_since' => $totalFiltered,
    'state_entries_before' => $beforeCount,
    'state_entries_after' => $afterCount,
    'changes_found' => $changesFound,
    'updates_done' => $updatesDone,
    'would_update_count' => $wouldUpdateCount,
    'skipped_no_map' => $skippedNoMap,
    'skipped_no_contact' => $skippedNoContact,
    'skipped_no_opp' => $skippedNoOpp,
    'skipped_aligned' => $skippedAligned,
    'deferred_rate_limit' => $deferredRateLimit,
    'deferred_lookup_http_error' => $deferredLookupHttpError,
    'errors_ghl' => $errorsGhl,
    'actions' => $actions,
    'elapsed_ms' => $elapsedMs,
    'api_calls' => [
        'contacts_search' => (int) $GLOBALS['api_calls_contacts_search'],
        'contacts_get'    => (int) $GLOBALS['api_calls_contacts_get'],
        'opps_search'     => (int) $GLOBALS['api_calls_opps_search'],
        'opps_get'        => (int) $GLOBALS['api_calls_opps_get'],
        'put_opp'         => (int) $GLOBALS['api_calls_put_opp'],
        'put_contact'     => (int) $GLOBALS['api_calls_put_contact'],
        'total'           => ghl_requests_used(),
    ],
    'rate_limit_meta' => $rateLimitMeta,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
