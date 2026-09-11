<?php
// ============================================
// CAPISoft -> GHL Stage Sync (JUAREZ) [PRODUCTIVO V1 OWNERFIX]
// - Open stages only
// - Reconcile opportunity owner + contact owner + stage + capisoft_stage
// - Opp owner writes are de-duplicated with per-clave sync memo when nested opp payload has no readable owner
// - OWNERFIX EXPERIMENT: dedicated trace log for owner-decision diagnostics
// - Re-open ONLY when CAPISoft is open and GHL status is abandoned
// - DO NOT TOUCH: won / lost from this cron
// - Simple match by project: if contact or opp in this pipeline is missing, skip
// - No opportunity creation here
// - Batched + checkpoint + shared lock + request budget
// - log_mode=normal|debug
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// ---------- Runtime config (hardcoded for Hostinger cron) ----------
$ALLOW_CLI_OVERRIDES      = false;
$RUNTIME_PROJECT_ID       = 1;
$RUNTIME_SINCE_DATE       = '2025-12-01';
$RUNTIME_DRY_RUN          = false;
$RUNTIME_BATCH_SIZE       = 30;
$RUNTIME_MAX_GHL_REQUESTS = 40;
$RUNTIME_LOG_MODE         = 'normal'; // normal|debug
$RUNTIME_RESOLUTION_TRACE = false;
$RUNTIME_API_TRACE        = false;
$RUNTIME_API_TRACE_BODIES = false;
$RUNTIME_API_TRACE_MAX    = 500;

// ---------- APIs ----------
$CAPISOFT_BASE  = 'https://api-3.capisoftware.com.mx/eu/capi-b/public/api/v2/ventas/oportunidades';
$CAPISOFT_TOKEN = getenv('CAPISOFT_TOKEN') ?: 'CAPISOFT_TOKEN';

$GHL_BASE_URL = 'https://services.leadconnectorhq.com';
$GHL_API_VER  = '2021-07-28';
$GHL_TOKEN    = getenv('GHL_TOKEN') ?: 'GHL_TOKEN';

// ---------- Project config ----------
$GHL_LOCATION_ID = '2cOAVW7auz2agTWyCnxF';
$GHL_PIPELINE_ID = 'RoLbh8p7EVEykLlVL2IB';
$GHL_CF_CAPISOFT_STAGE_ID = '9akn1HKwx4LzwwKING1w';

$CAPI_TO_GHL_STAGE = [
    1   => 'a8bfe726-c88c-4a2d-8adc-629f8479d1e2', // Asignado
    241 => '7375a68e-7b09-4c26-8053-6e6ed746dc18', // Buscando contacto CAPI
    242 => '1f6d2e65-a41c-40c5-b2d2-8dd58fdabb3f', // Enfriado
    351 => '91b69ed5-46bc-4f1b-ac27-6a4abd17e0d6', // Nurture
    2   => '9497216f-5867-43f8-aafe-0c8e7b0596f5', // Seguimiento
    243 => 'f86da8db-8e49-42a4-b4ac-291257270acb', // Negociación
    // Apartado pending when etapa_id is confirmed.
];
$OPEN_STAGE_IDS = array_map('intval', array_keys($CAPI_TO_GHL_STAGE));

$RESPONSABLE_ID_TO_GHL_USER_ID = [
    63  => 'wiEh4slRyJ4kcTrvmbaX', // Juan Arceo
    65  => 'PCmfjjFzA0M7T0FJeHJS', // Raul Santiago
    141 => 'rmnpri2YtCeVlkjwMKk8', // Denisse Jalife
    142 => 'VFd98tdUzrwHPFw71bZW', // Sofia Santos
    233 => 'XJFKjmrZwSILCLAxIA3U', // Diana Gonzalez
    // 173 => 'TODO', // Maria Preciado
];

// ---------- CLI ----------
function get_arg(string $key): ?string {
    global $argv;
    foreach ((array)$argv as $a) {
        if (strpos($a, "--{$key}=") === 0) {
            return substr($a, strlen($key) + 3);
        }
    }
    return null;
}
function get_bool_arg(string $key, bool $default): bool {
    $v = get_arg($key);
    if ($v === null) return $default;
    return in_array(strtolower(trim($v)), ['1','true','yes','y','on'], true);
}
function get_int_arg(string $key, int $default): int {
    $v = get_arg($key);
    return ($v === null || $v === '') ? $default : (int)$v;
}

$projectId       = $RUNTIME_PROJECT_ID;
$sinceDate       = $RUNTIME_SINCE_DATE;
$dryRun          = $RUNTIME_DRY_RUN;
$batchSize       = $RUNTIME_BATCH_SIZE;
$maxGhlRequests  = $RUNTIME_MAX_GHL_REQUESTS;
$logMode         = $RUNTIME_LOG_MODE;
$resolutionTrace = $RUNTIME_RESOLUTION_TRACE;
$apiTrace        = $RUNTIME_API_TRACE;
$apiTraceBodies  = $RUNTIME_API_TRACE_BODIES;
$apiTraceMax     = $RUNTIME_API_TRACE_MAX;

if ($ALLOW_CLI_OVERRIDES) {
    $projectId       = get_int_arg('project_id', $RUNTIME_PROJECT_ID);
    $sinceDate       = get_arg('since_date') ?: $RUNTIME_SINCE_DATE;
    $dryRun          = get_bool_arg('dry_run', $RUNTIME_DRY_RUN);
    $batchSize       = max(1, get_int_arg('batch_size', $RUNTIME_BATCH_SIZE));
    $maxGhlRequests  = max(1, get_int_arg('max_ghl_requests', $RUNTIME_MAX_GHL_REQUESTS));
    $logMode         = strtolower(get_arg('log_mode') ?: $RUNTIME_LOG_MODE);
    $resolutionTrace = get_bool_arg('resolution_trace', $RUNTIME_RESOLUTION_TRACE || $logMode === 'debug');
    $apiTrace        = get_bool_arg('api_trace', $RUNTIME_API_TRACE);
    $apiTraceBodies  = get_bool_arg('api_trace_bodies', $RUNTIME_API_TRACE_BODIES);
    $apiTraceMax     = get_int_arg('api_trace_max_chars', $RUNTIME_API_TRACE_MAX);
}
if (!in_array($logMode, ['normal', 'debug'], true)) $logMode = 'normal';

// ---------- Paths ----------
$baseDir      = __DIR__;
$storageDir   = $baseDir . '/storage';
$logsDir      = $baseDir . '/logs';
@mkdir($storageDir, 0755, true);
@mkdir($logsDir, 0755, true);

$stateFile    = $storageDir . '/capisoft_state_project_1_stage_productive.json';
$progressFile = $storageDir . '/capisoft_progress_project_1_stage_productive.json';
$lockFile     = $storageDir . '/capisoft_shared_project_1.lock';
$logFile      = $logsDir . '/capisoft_stage_juarez_productive.log';
$lockSkipFile = $logsDir . '/stage_lock_skips.log';
$ownerFixTraceFile = $logsDir . '/capisoft_stage_juarez_ownerfix_trace.log';

// ---------- Globals ----------
$GLOBALS['api_calls_contacts_search'] = 0;
$GLOBALS['api_calls_contacts_get']    = 0;
$GLOBALS['api_calls_put_opp']         = 0;
$GLOBALS['api_calls_put_contact']     = 0;
$GLOBALS['api_trace_enabled']         = $apiTrace;
$GLOBALS['api_trace_bodies_enabled']  = $apiTraceBodies;
$GLOBALS['api_trace_max_chars']       = $apiTraceMax;
$GLOBALS['api_trace_log_file']        = $logFile;
$GLOBALS['ownerfix_trace_log_file']   = $ownerFixTraceFile;
$GLOBALS['resolution_trace_enabled']  = $resolutionTrace;
$GLOBALS['log_mode']                  = $logMode;
$GLOBALS['run_id']                    = date('Ymd_His') . '_' . substr(md5((string)microtime(true)), 0, 6);

// ---------- Helpers ----------
function mask_email(?string $email): ?string {
    if (!$email || strpos($email, '@') === false) return $email;
    [$u, $d] = explode('@', $email, 2);
    if ($u === '') return '***@' . $d;
    return substr($u, 0, 1) . '***@' . $d;
}
function mask_phone(?string $phone): ?string {
    if (!$phone) return null;
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') return null;
    return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
}
function one_line($v, int $max=800): string {
    if (is_array($v) || is_object($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $v = preg_replace('/\s+/', ' ', str_replace(["\r","\n","\t"], ' ', (string)$v));
    $v = trim((string)$v);
    return (strlen($v) > $max) ? substr($v, 0, $max) . '...' : $v;
}
function log_line(string $file, string $line): void {
    @mkdir(dirname($file), 0755, true);
    file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
}
function is_debug_mode(): bool { return (($GLOBALS['log_mode'] ?? 'normal') === 'debug'); }
function log_debug(string $file, string $line): void {
    if (is_debug_mode()) log_line($file, $line);
}
function log_resolution(string $file, array $data): void {
    if (!empty($GLOBALS['resolution_trace_enabled'])) {
        log_line($file, date('c') . ' RESOLUTION ' . one_line($data, 1200));
    }
}
function ownerfix_scalar($value): string {
    if ($value === null || $value === '') return 'null';
    if (is_bool($value)) return $value ? '1' : '0';
    return one_line((string)$value, 180);
}
function log_ownerfix_trace(string $event, array $fields = []): void {
    $file = $GLOBALS['ownerfix_trace_log_file'] ?? null;
    if (!$file) return;
    $parts = [date('c'), $event];
    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . ownerfix_scalar($value);
    }
    log_line($file, implode(' ', $parts));
}
function read_json_file(string $path, array $default): array {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    $json = json_decode((string)$raw, true);
    return is_array($json) ? $json : $default;
}
function write_json_file(string $path, array $data): void {
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function read_state(string $path): array {
    return read_json_file($path, ['last_run_at'=>null,'by_clave'=>[],'meta'=>[]]);
}
function read_progress(string $path): array {
    return read_json_file($path, ['next_index'=>0,'last_clave'=>null,'last_run_at'=>null,'last_stop_reason'=>null,'full_passes'=>0]);
}
function normalize_email(?string $email): ?string {
    if ($email === null) return null;
    $e = strtolower(trim($email));
    return $e === '' ? null : $e;
}
function split_emails(?string $raw): array {
    if (!$raw) return [];
    $parts = preg_split('/[;,]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $e = normalize_email($p);
        if ($e && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[] = $e;
    }
    return array_values(array_unique($out));
}
function normalize_phone(?string $raw): ?string {
    if ($raw === null) return null;
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') return null;
    if (strpos($digits, '521') === 0) $digits = '52' . substr($digits, 3);
    if (strlen($digits) === 10) $digits = '52' . $digits;
    return $digits;
}
function split_phones(?string $raw): array {
    if (!$raw) return [];
    $parts = preg_split('/[;,]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $n = normalize_phone($p);
        if ($n) $out[] = $n;
    }
    return array_values(array_unique($out));
}
function extract_attempt_http(array $lookup, string $channel): ?int {
    foreach (array_reverse($lookup['attempts'] ?? []) as $a) {
        if (($a['type'] ?? null) === $channel) return isset($a['http']) ? (int)$a['http'] : null;
    }
    return null;
}
function extract_attempt_count(array $lookup, string $channel): ?int {
    foreach (array_reverse($lookup['attempts'] ?? []) as $a) {
        if (($a['type'] ?? null) === $channel) return isset($a['count']) ? (int)$a['count'] : null;
    }
    return null;
}

function http_json(string $method, string $url, array $headers, $payload=null, int $timeout=25): array {
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
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $started) * 1000);

    if (!empty($GLOBALS['api_trace_enabled']) && is_debug_mode()) {
        $line = date('c') . " API_CALL method={$method} url={$url} http={$http} ms={$ms}";
        if ($err !== '') $line .= ' curl_err=' . one_line($err, 200);
        if (!empty($GLOBALS['api_trace_bodies_enabled'])) {
            if ($requestBody !== null) $line .= ' req=' . one_line($requestBody, (int)$GLOBALS['api_trace_max_chars']);
            if ($body !== false && $body !== null && $body !== '') $line .= ' resp=' . one_line($body, (int)$GLOBALS['api_trace_max_chars']);
        }
        log_line($GLOBALS['api_trace_log_file'], $line);
    }

    $json = null;
    if ($body !== false && $body !== null && $body !== '') {
        $tmp = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
    }
    return ['http'=>$http,'body'=>$body,'json'=>$json,'err'=>$err,'ms'=>$ms];
}
function ghl_headers(): array {
    global $GHL_TOKEN, $GHL_API_VER;
    return [
        'Authorization: Bearer ' . $GHL_TOKEN,
        'Version: ' . $GHL_API_VER,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}
function capi_headers(): array {
    global $CAPISOFT_TOKEN;
    return [
        'Authorization: Bearer ' . $CAPISOFT_TOKEN,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}
function api_budget_used(): int {
    return (int)$GLOBALS['api_calls_contacts_search']
        + (int)$GLOBALS['api_calls_contacts_get']
        + (int)$GLOBALS['api_calls_put_opp']
        + (int)$GLOBALS['api_calls_put_contact'];
}
function is_rate_limited(array $resp): bool { return ((int)($resp['http'] ?? 0) === 429); }

function capi_fetch_snapshot(int $projectId, string $sinceDate): array {
    global $CAPISOFT_BASE;
    $url = $CAPISOFT_BASE . '?proyecto_id=' . urlencode((string)$projectId);
    $resp = http_json('GET', $url, capi_headers(), null, 60);
    if ((int)$resp['http'] >= 400 || !is_array($resp['json'])) return ['ok'=>false,'resp'=>$resp,'rows'=>[]];
    $raw = $resp['json'];
    $rows = [];
    if (isset($raw['data']) && is_array($raw['data'])) $rows = $raw['data'];
    elseif (isset($raw[0]) || empty($raw)) $rows = $raw;
    $cut = strtotime($sinceDate . ' 00:00:00');
    $filtered = [];
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $created = strtotime((string)($r['created_at'] ?? ''));
        if ($created !== false && $created < $cut) continue;
        $filtered[] = $r;
    }
    usort($filtered, function($a,$b){
        $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($ta === $tb) return (int)($a['clave'] ?? 0) <=> (int)($b['clave'] ?? 0);
        return $ta <=> $tb;
    });
    return ['ok'=>true,'resp'=>$resp,'rows'=>$filtered,'total_raw'=>count($rows)];
}
function capi_row_to_current(array $row): array {
    return [
        'etapa_id'       => isset($row['etapa_id']) ? (int)$row['etapa_id'] : (isset($row['etapaId']) ? (int)$row['etapaId'] : null),
        'etapa'          => (string)($row['etapa'] ?? $row['etapa_nombre'] ?? ''),
        'updated_by'     => $row['updated_by'] ?? null,
        'created_at'     => $row['created_at'] ?? null,
        'updated_at'     => $row['updated_at'] ?? null,
        'responsable'    => $row['responsable'] ?? null,
        'responsable_id' => isset($row['responsable_id']) ? (int)$row['responsable_id'] : null,
        'emails'         => $row['emails'] ?? $row['email'] ?? null,
        'telefonos'      => $row['telefonos'] ?? $row['telefono'] ?? null,
        'id'             => $row['id'] ?? null,
    ];
}
function ghl_contacts_search_by_field(string $field, string $value): array {
    global $GHL_BASE_URL, $GHL_LOCATION_ID;
    $payload = [
        'locationId' => $GHL_LOCATION_ID,
        'page' => 1,
        'pageLimit' => 10,
        'filters' => [[ 'field' => $field, 'operator' => 'eq', 'value' => $value ]],
    ];
    $resp = http_json('POST', $GHL_BASE_URL . '/contacts/search', ghl_headers(), $payload);
    $GLOBALS['api_calls_contacts_search']++;
    $contacts = [];
    if (is_array($resp['json']) && isset($resp['json']['contacts']) && is_array($resp['json']['contacts'])) {
        $contacts = $resp['json']['contacts'];
    }
    return ['resp'=>$resp, 'contacts'=>$contacts];
}
function ghl_get_contact(string $contactId): array {
    global $GHL_BASE_URL;
    $resp = http_json('GET', $GHL_BASE_URL . '/contacts/' . rawurlencode($contactId), ghl_headers(), null);
    $GLOBALS['api_calls_contacts_get']++;
    $contact = null;
    if ((int)$resp['http'] < 400 && is_array($resp['json'])) {
        if (isset($resp['json']['contact']) && is_array($resp['json']['contact'])) $contact = $resp['json']['contact'];
        elseif (isset($resp['json']['id'])) $contact = $resp['json'];
    }
    return ['resp'=>$resp, 'contact'=>$contact];
}
function ghl_put_opp(string $oppId, array $payload): array {
    global $GHL_BASE_URL;
    $resp = http_json('PUT', $GHL_BASE_URL . '/opportunities/' . rawurlencode($oppId), ghl_headers(), $payload);
    $GLOBALS['api_calls_put_opp']++;
    return $resp;
}
function ghl_put_contact(string $contactId, array $payload): array {
    global $GHL_BASE_URL;
    $resp = http_json('PUT', $GHL_BASE_URL . '/contacts/' . rawurlencode($contactId), ghl_headers(), $payload);
    $GLOBALS['api_calls_put_contact']++;
    return $resp;
}
function find_pipeline_opp(array $contact, string $pipelineId): array {
    $opps = [];
    if (isset($contact['opportunities']) && is_array($contact['opportunities'])) $opps = $contact['opportunities'];
    foreach ($opps as $opp) {
        if (!is_array($opp)) continue;
        if ((string)($opp['pipelineId'] ?? '') === $pipelineId) {
            return ['opp'=>$opp, 'other_pipeline_ids'=>[]];
        }
    }
    $other = [];
    foreach ($opps as $opp) {
        if (!is_array($opp)) continue;
        $pid = (string)($opp['pipelineId'] ?? '');
        if ($pid !== '') $other[] = $pid;
    }
    return ['opp'=>null, 'other_pipeline_ids'=>array_values(array_unique($other))];
}
function ghl_find_contact_and_opp(string $rawEmail, ?string $rawPhone, string $pipelineId): array {
    $emails = split_emails($rawEmail);
    $phones = split_phones($rawPhone);
    $attempts = [];
    $matchedContact = null;
    $matchedBy = null;
    $matchedValue = null;
    $otherPipelineIds = [];

    foreach ($emails as $email) {
        $sr = ghl_contacts_search_by_field('email', $email);
        $contacts = $sr['contacts'];
        $attempts[] = ['type'=>'email','value'=>$email,'http'=>(int)$sr['resp']['http'],'count'=>count($contacts)];
        if (is_rate_limited($sr['resp'])) {
            return ['decision'=>'rate_limit','lookup'=>['attempts'=>$attempts],'email_tried'=>$emails,'phone_tried'=>null];
        }
        if ((int)$sr['resp']['http'] >= 400 && (int)$sr['resp']['http'] !== 404) {
            return ['decision'=>'lookup_http_error','lookup'=>['attempts'=>$attempts,'last_resp'=>$sr['resp']],'email_tried'=>$emails,'phone_tried'=>null];
        }
        if (!empty($contacts)) {
            $matchedContact = $contacts[0];
            $matchedBy = 'email';
            $matchedValue = $email;
            break;
        }
    }

    if (!$matchedContact) {
        foreach ($phones as $phone) {
            $sr = ghl_contacts_search_by_field('phone', '+' . $phone);
            $contacts = $sr['contacts'];
            $attempts[] = ['type'=>'phone','value'=>$phone,'http'=>(int)$sr['resp']['http'],'count'=>count($contacts)];
            if (is_rate_limited($sr['resp'])) {
                return ['decision'=>'rate_limit','lookup'=>['attempts'=>$attempts],'email_tried'=>$emails,'phone_tried'=>$phone];
            }
            if ((int)$sr['resp']['http'] >= 400 && (int)$sr['resp']['http'] !== 404) {
                return ['decision'=>'lookup_http_error','lookup'=>['attempts'=>$attempts,'last_resp'=>$sr['resp']],'email_tried'=>$emails,'phone_tried'=>$phone];
            }
            if (!empty($contacts)) {
                $matchedContact = $contacts[0];
                $matchedBy = 'phone';
                $matchedValue = $phone;
                break;
            }
        }
    }

    if (!$matchedContact) {
        return ['decision'=>'no_contact','lookup'=>['attempts'=>$attempts],'email_tried'=>$emails,'phone_tried'=>($phones[0] ?? null)];
    }

    $oppInfo = find_pipeline_opp($matchedContact, $pipelineId);
    $opp = $oppInfo['opp'];
    $otherPipelineIds = $oppInfo['other_pipeline_ids'];
    if (!$opp) {
        $cg = ghl_get_contact((string)$matchedContact['id']);
        if (is_rate_limited($cg['resp'])) {
            return ['decision'=>'rate_limit','lookup'=>['attempts'=>$attempts],'email_tried'=>$emails,'phone_tried'=>($phones[0] ?? null),'matched_contact'=>$matchedContact];
        }
        if ((int)$cg['resp']['http'] >= 400 && (int)$cg['resp']['http'] !== 404) {
            return ['decision'=>'lookup_http_error','lookup'=>['attempts'=>$attempts,'last_resp'=>$cg['resp']],'email_tried'=>$emails,'phone_tried'=>($phones[0] ?? null),'matched_contact'=>$matchedContact];
        }
        if (is_array($cg['contact'])) {
            $matchedContact = $cg['contact'];
            $oppInfo = find_pipeline_opp($matchedContact, $pipelineId);
            $opp = $oppInfo['opp'];
            $otherPipelineIds = $oppInfo['other_pipeline_ids'];
        }
    }

    return [
        'decision' => $opp ? 'matched' : 'no_opp',
        'lookup' => ['attempts'=>$attempts],
        'contact' => $matchedContact,
        'opp' => $opp,
        'other_pipeline_ids' => $otherPipelineIds,
        'matched_by' => $matchedBy,
        'matched_value' => $matchedValue,
        'email_tried' => $emails,
        'phone_tried' => ($phones[0] ?? null),
    ];
}

// ---------- Lock ----------
$lockFp = fopen($lockFile, 'c+');
if (!$lockFp) {
    echo json_encode(['ok'=>false,'error'=>'cannot_open_lock_file'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    log_line($lockSkipFile, date('c') . ' LOCK_BUSY run_id=' . $GLOBALS['run_id']);
    exit(0);
}

$runStarted = microtime(true);
log_line($logFile, date('c') . " RUN_START run_id={$GLOBALS['run_id']} dry_run=" . ($dryRun?1:0) . " since_date={$sinceDate} batch_size={$batchSize} max_ghl_requests={$maxGhlRequests} log_mode={$logMode}");

// ---------- State ----------
$state = read_state($stateFile);
$progress = read_progress($progressFile);
$stateEntriesBefore = count($state['by_clave'] ?? []);
$fullPassesBefore = (int)($progress['full_passes'] ?? 0);

if (!$GHL_TOKEN || $GHL_TOKEN === 'GHL_TOKEN' || !$CAPISOFT_TOKEN || $CAPISOFT_TOKEN === 'CAPISOFT_TOKEN') {
    log_line($logFile, date('c') . ' ERROR CONFIG missing_tokens');
    flock($lockFp, LOCK_UN); fclose($lockFp);
    echo json_encode(['ok'=>false,'error'=>'missing_tokens'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

$snapshot = capi_fetch_snapshot($projectId, $sinceDate);
if (!$snapshot['ok']) {
    log_line($logFile, date('c') . ' ERROR CAPI GET proyecto_id=' . $projectId . ' http=' . (int)($snapshot['resp']['http'] ?? 0) . ' err=' . one_line($snapshot['resp']['err'] ?? '', 120) . ' body=' . one_line($snapshot['resp']['body'] ?? '', 300));
    flock($lockFp, LOCK_UN); fclose($lockFp);
    echo json_encode(['ok'=>false,'error'=>'capi_fetch_failed','http'=>(int)($snapshot['resp']['http'] ?? 0)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
$rows = $snapshot['rows'];
$totalFiltered = count($rows);
$startIndex = min((int)($progress['next_index'] ?? 0), max(0, $totalFiltered));

log_ownerfix_trace('OWNERFIX_RUN_START', [
    'run_id' => $GLOBALS['run_id'],
    'since_date' => $sinceDate,
    'dry_run' => $dryRun,
    'start_index' => $startIndex,
    'progress_next_index_before' => (int)($progress['next_index'] ?? 0),
    'full_passes_before' => $fullPassesBefore,
    'state_entries_before' => $stateEntriesBefore,
    'total_filtered' => $totalFiltered,
    'batch_size' => $batchSize,
    'max_ghl_requests' => $maxGhlRequests,
]);

$changesFound = 0;
$updatesDone = 0;
$wouldUpdate = 0;
$skippedNoMap = 0;
$skippedNoOwnerMap = 0;
$skippedNoContact = 0;
$skippedNoOpp = 0;
$skippedAligned = 0;
$deferredRateLimit = 0;
$deferredLookupHttpError = 0;
$errorsGhl = 0;
$actions = [
    'reopen_applied'=>0,
    'reopen_would_apply'=>0,
    'contact_owner_applied'=>0,
    'contact_owner_would'=>0,
    'opp_owner_applied'=>0,
    'opp_owner_would'=>0,
    'stage_applied'=>0,
    'stage_would'=>0,
    'cf_applied'=>0,
    'cf_would'=>0,
];

$idx = $startIndex;
$itemsProcessed = 0;
$lastClave = null;
$stopReason = 'batch_limit';
$fullPassCompleted = false;

while ($idx < $totalFiltered) {
    if ($itemsProcessed >= $batchSize) {
        $stopReason = 'batch_limit';
        break;
    }
    if (api_budget_used() >= $maxGhlRequests) {
        $stopReason = 'request_budget';
        break;
    }

    $row = $rows[$idx];
    $clave = (string)($row['clave'] ?? $row['id'] ?? ('idx_' . $idx));
    $lastClave = $clave;
    $current = capi_row_to_current($row);
    $stageId = (int)($current['etapa_id'] ?? 0);
    $rawEmail = $current['emails'] ?? null;
    $rawPhone = $current['telefonos'] ?? null;
    $rawRespId = isset($current['responsable_id']) ? (int)$current['responsable_id'] : null;

    $previousStateEntry = (isset($state['by_clave'][$clave]) && is_array($state['by_clave'][$clave]))
        ? $state['by_clave'][$clave]
        : null;
    $isFirstSeen = ($previousStateEntry === null);
    $syncMeta = (is_array($previousStateEntry['_sync'] ?? null)) ? $previousStateEntry['_sync'] : [];

    if ($isFirstSeen) {
        $state['by_clave'][$clave] = $current;
        log_debug($logFile, date('c') . ' SEED FIRST_SEEN clave=' . $clave . ' etapa_id=' . $stageId . ' etapa="' . addslashes((string)($current['etapa'] ?? '')) . '" email=' . one_line((string)$rawEmail, 120) . ' phone=' . one_line((string)$rawPhone, 60));
    }

    $resTrace = [
        'clave' => $clave,
        'email_original' => $rawEmail,
        'emails_tried' => [],
        'phone_original' => $rawPhone,
        'phone_tried' => null,
        'email_search_http' => null,
        'email_search_count' => null,
        'phone_search_http' => null,
        'phone_search_count' => null,
        'contact_id' => null,
        'opp_id' => null,
        'matched_by' => null,
        'matched_value' => null,
        'decision' => null,
        'first_seen' => $isFirstSeen,
    ];

    if (!in_array($stageId, $OPEN_STAGE_IDS, true)) {
        $changesFound++;
        $skippedNoMap++;
        $resTrace['decision'] = 'skip_not_open_stage';
        log_debug($logFile, date('c') . ' SKIP NO_MAP clave=' . $clave . ' etapa_id=' . $stageId . ' etapa="' . addslashes((string)($current['etapa'] ?? '')) . '" decision=skip_not_open_stage');
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }

    $lookup = ghl_find_contact_and_opp((string)$rawEmail, $rawPhone, $GHL_PIPELINE_ID);
    $resTrace['emails_tried'] = $lookup['email_tried'] ?? [];
    $resTrace['phone_tried'] = $lookup['phone_tried'] ?? null;
    $resTrace['email_search_http'] = extract_attempt_http($lookup['lookup'] ?? [], 'email');
    $resTrace['email_search_count'] = extract_attempt_count($lookup['lookup'] ?? [], 'email');
    $resTrace['phone_search_http'] = extract_attempt_http($lookup['lookup'] ?? [], 'phone');
    $resTrace['phone_search_count'] = extract_attempt_count($lookup['lookup'] ?? [], 'phone');
    $resTrace['matched_by'] = $lookup['matched_by'] ?? null;
    $resTrace['matched_value'] = $lookup['matched_value'] ?? null;

    if (($lookup['decision'] ?? null) === 'rate_limit') {
        $deferredRateLimit++;
        $stopReason = 'rate_limit_contact_lookup';
        log_line($logFile, date('c') . ' DEFER RATE_LIMIT clave=' . $clave . ' stage=contact_lookup');
        $resTrace['decision'] = 'lookup_rate_limited';
        log_resolution($logFile, $resTrace);
        break;
    }
    if (($lookup['decision'] ?? null) === 'lookup_http_error') {
        $deferredLookupHttpError++;
        $resTrace['decision'] = 'lookup_http_error';
        $lastResp = $lookup['lookup']['last_resp'] ?? [];
        log_line($logFile, date('c') . ' ERROR CONTACT_LOOKUP clave=' . $clave . ' http=' . (int)($lastResp['http'] ?? 0) . ' err=' . one_line($lastResp['err'] ?? '', 120) . ' body=' . one_line($lastResp['body'] ?? '', 200));
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }
    if (($lookup['decision'] ?? null) === 'no_contact') {
        $skippedNoContact++;
        $resTrace['decision'] = 'skip_no_contact';
        log_debug($logFile, date('c') . ' SKIP NO_CONTACT clave=' . $clave . ' email=' . one_line((string)$rawEmail, 120) . ' phone=' . normalize_phone($rawPhone) . ' decision=skip_no_contact');
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }
    if (($lookup['decision'] ?? null) === 'no_opp') {
        $skippedNoOpp++;
        $contactId = (string)($lookup['contact']['id'] ?? '');
        $resTrace['contact_id'] = $contactId !== '' ? $contactId : null;
        $resTrace['decision'] = 'skip_no_opp';
        log_debug($logFile, date('c') . ' SKIP NO_OPP clave=' . $clave . ' contact=' . $contactId . ' decision=skip_no_opp');
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }

    $contact = $lookup['contact'];
    $opp = $lookup['opp'];
    $contactId = (string)($contact['id'] ?? '');
    $oppId = (string)($opp['id'] ?? '');
    $currentStatus = strtolower((string)($opp['status'] ?? 'open'));
    $currentStageId = (string)($opp['pipelineStageId'] ?? '');
    $currentContactOwner = trim((string)($contact['assignedTo'] ?? ''));
    $currentOppOwner = trim((string)($opp['assignedTo'] ?? $opp['assignedUserId'] ?? ''));
    $targetStageId = (string)($CAPI_TO_GHL_STAGE[$stageId] ?? '');
    $targetOwnerId = ($rawRespId !== null && isset($RESPONSABLE_ID_TO_GHL_USER_ID[$rawRespId])) ? (string)$RESPONSABLE_ID_TO_GHL_USER_ID[$rawRespId] : null;
    $targetCfValue = $projectId . '|' . $stageId . '|' . (string)($current['etapa'] ?? '');

    // Opportunity owner can be absent in the embedded opportunity object returned through the contact lookup.
    // Keep a small success memo so the cron does not write the exact same assignedTo on every full pass.
    // The memo is invalidated automatically when the opportunity id or the CAPISoft-derived target owner changes.
    $oppOwnerMemoConfirmed = !empty($syncMeta['opp_owner_confirmed']);
    $oppOwnerMemoOppId = (string)($syncMeta['opp_owner_opp_id'] ?? '');
    $oppOwnerMemoTargetId = (string)($syncMeta['opp_owner_target_id'] ?? '');
    $oppOwnerAlreadyConfirmed = (
        $targetOwnerId !== null
        && $oppOwnerMemoConfirmed
        && $oppOwnerMemoOppId === $oppId
        && $oppOwnerMemoTargetId === $targetOwnerId
    );

    $ownerFixMemoMatchesOpp = ($oppOwnerMemoOppId !== '' && $oppOwnerMemoOppId === $oppId);
    $ownerFixMemoMatchesTarget = ($targetOwnerId !== null && $oppOwnerMemoTargetId !== '' && $oppOwnerMemoTargetId === $targetOwnerId);
    $ownerFixDecision = null;

    $resTrace['contact_id'] = $contactId ?: null;
    $resTrace['opp_id'] = $oppId ?: null;

    // Do not touch won/lost from this cron.
    if (in_array($currentStatus, ['won', 'lost'], true)) {
        $skippedAligned++;
        $resTrace['decision'] = 'skip_closed_not_touched';
        log_debug($logFile, date('c') . ' SKIP CLOSED_NOT_TOUCHED clave=' . $clave . ' opp=' . $oppId . ' status=' . $currentStatus);
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }

    $needsReopen = ($currentStatus === 'abandoned');
    $needsContactOwner = ($targetOwnerId && $currentContactOwner !== '' && $currentContactOwner !== $targetOwnerId);
    if ($targetOwnerId && $currentContactOwner === '') $needsContactOwner = true;

    $needsOppOwner = false;
    if ($targetOwnerId) {
        if ($currentOppOwner !== '') {
            // If the embedded opportunity already exposes an owner, preserve the original direct reconciliation.
            $needsOppOwner = ($currentOppOwner !== $targetOwnerId);

            if ($needsOppOwner) {
                $ownerFixDecision = 'UPDATE_REQUIRED_OWNER_MISMATCH';
            } else {
                $ownerFixDecision = 'SKIP_ALREADY_ALIGNED_FROM_GHL';
                $syncMeta['opp_owner_confirmed'] = true;
                $syncMeta['opp_owner_opp_id'] = $oppId;
                $syncMeta['opp_owner_target_id'] = $targetOwnerId;
                $syncMeta['opp_owner_confirmed_at'] = date('c');
                $syncMeta['opp_owner_confirmed_via'] = 'readable_opp_owner';
            }
        } else {
            // If the owner is not readable from the embedded opportunity object,
            // write once and then rely on the success memo to avoid duplicate PUTs.
            $needsOppOwner = !$oppOwnerAlreadyConfirmed;
            if ($needsOppOwner) {
                $ownerFixDecision = 'UPDATE_REQUIRED_NO_OWNER_VISIBLE_NO_MEMO';
            } else {
                $ownerFixDecision = 'SKIP_CONFIRMED_BY_STATE';
                log_debug($logFile, date('c') . ' SKIP OPP_OWNER_MEMO_CONFIRMED clave=' . $clave . ' opp=' . $oppId . ' to=' . $targetOwnerId);
            }
        }
    } else {
        $ownerFixDecision = 'SKIP_NO_TARGET_OWNER_MAP';
    }

    log_ownerfix_trace('OWNER_DECISION', [
        'run_id' => $GLOBALS['run_id'],
        'clave' => $clave,
        'stage_id' => $stageId,
        'opp' => $oppId,
        'target_owner' => $targetOwnerId,
        'current_opp_owner' => $currentOppOwner,
        'owner_readable' => ($currentOppOwner !== ''),
        'memo_confirmed' => $oppOwnerMemoConfirmed,
        'memo_opp_id' => $oppOwnerMemoOppId,
        'memo_target_owner' => $oppOwnerMemoTargetId,
        'memo_matches_opp' => $ownerFixMemoMatchesOpp,
        'memo_matches_target' => $ownerFixMemoMatchesTarget,
        'already_confirmed' => $oppOwnerAlreadyConfirmed,
        'needs_opp_owner' => $needsOppOwner,
        'decision' => $ownerFixDecision,
    ]);

    $needsStage = ($targetStageId !== '' && $currentStageId !== $targetStageId);

    $currentCfValue = null;
    foreach (($contact['customFields'] ?? []) as $cf) {
        if ((string)($cf['id'] ?? '') === $GHL_CF_CAPISOFT_STAGE_ID) {
            $currentCfValue = (string)($cf['value'] ?? '');
            break;
        }
    }
    $needsCf = ((string)$currentCfValue !== (string)$targetCfValue);

    if (!$needsReopen && !$needsContactOwner && !$needsOppOwner && !$needsStage && !$needsCf) {
        $skippedAligned++;
        $resTrace['decision'] = 'aligned';
        log_debug($logFile, date('c') . ' SKIP ALIGNED clave=' . $clave . ' opp=' . $oppId . ' status=' . $currentStatus);
        log_resolution($logFile, $resTrace);
        $idx++; $itemsProcessed++;
        continue;
    }

    $changesFound++;

    if ($needsReopen) {
        if ($dryRun) {
            $wouldUpdate++; $actions['reopen_would_apply']++;
            log_debug($logFile, date('c') . ' DRY GHL_OPP_REOPEN clave=' . $clave . ' opp=' . $oppId . ' status=open from=abandoned');
        } else {
            $resp = ghl_put_opp($oppId, ['status'=>'open']);
            if ((int)$resp['http'] >= 200 && (int)$resp['http'] < 300) {
                $updatesDone++; $actions['reopen_applied']++;
                log_line($logFile, date('c') . ' OK GHL_OPP_REOPEN clave=' . $clave . ' opp=' . $oppId . ' status=open from=abandoned');
            } else {
                $errorsGhl++;
                log_line($logFile, date('c') . ' ERROR GHL_OPP_REOPEN clave=' . $clave . ' opp=' . $oppId . ' http=' . (int)$resp['http'] . ' err=' . one_line($resp['err'] ?? '', 120) . ' body=' . one_line($resp['body'] ?? '', 200));
            }
        }
    }

    if ($needsContactOwner && $targetOwnerId) {
        if ($dryRun) {
            $wouldUpdate++; $actions['contact_owner_would']++;
            log_debug($logFile, date('c') . ' DRY GHL_CONTACT_OWNER_UPDATE clave=' . $clave . ' contact=' . $contactId . ' from=' . ($currentContactOwner ?: 'null') . ' to=' . $targetOwnerId);
        } else {
            $resp = ghl_put_contact($contactId, ['assignedTo' => $targetOwnerId]);
            if ((int)$resp['http'] >= 200 && (int)$resp['http'] < 300) {
                $updatesDone++; $actions['contact_owner_applied']++;
                log_line($logFile, date('c') . ' OK GHL_CONTACT_OWNER_UPDATE clave=' . $clave . ' contact=' . $contactId . ' to=' . $targetOwnerId);
            } else {
                $errorsGhl++;
                log_line($logFile, date('c') . ' ERROR GHL_CONTACT_OWNER_UPDATE clave=' . $clave . ' contact=' . $contactId . ' http=' . (int)$resp['http'] . ' err=' . one_line($resp['err'] ?? '', 120) . ' body=' . one_line($resp['body'] ?? '', 200));
            }
        }
    }

    if ($needsOppOwner && $targetOwnerId) {
        if ($dryRun) {
            $wouldUpdate++; $actions['opp_owner_would']++;
            log_debug($logFile, date('c') . ' DRY GHL_OPP_OWNER_UPDATE clave=' . $clave . ' opp=' . $oppId . ' from=' . ($currentOppOwner ?: 'null') . ' to=' . $targetOwnerId);
            log_ownerfix_trace('OWNER_SYNC_DRY_WOULD_UPDATE', [
                'run_id' => $GLOBALS['run_id'],
                'clave' => $clave,
                'opp' => $oppId,
                'target_owner' => $targetOwnerId,
                'current_opp_owner' => $currentOppOwner,
                'memo_saved' => false,
            ]);
        } else {
            $resp = ghl_put_opp($oppId, ['assignedTo' => $targetOwnerId]);
            if ((int)$resp['http'] >= 200 && (int)$resp['http'] < 300) {
                $updatesDone++; $actions['opp_owner_applied']++;
                $syncMeta['opp_owner_confirmed'] = true;
                $syncMeta['opp_owner_opp_id'] = $oppId;
                $syncMeta['opp_owner_target_id'] = $targetOwnerId;
                $syncMeta['opp_owner_confirmed_at'] = date('c');
                $syncMeta['opp_owner_confirmed_via'] = 'put_opp';
                log_line($logFile, date('c') . ' OK GHL_OPP_OWNER_UPDATE clave=' . $clave . ' opp=' . $oppId . ' to=' . $targetOwnerId);
                log_ownerfix_trace('OWNER_SYNC_OK_MEMO_SAVED', [
                    'run_id' => $GLOBALS['run_id'],
                    'clave' => $clave,
                    'opp' => $oppId,
                    'target_owner' => $targetOwnerId,
                    'http' => (int)$resp['http'],
                    'memo_saved' => true,
                ]);
            } else {
                $errorsGhl++;
                log_line($logFile, date('c') . ' ERROR GHL_OPP_OWNER_UPDATE clave=' . $clave . ' opp=' . $oppId . ' http=' . (int)$resp['http'] . ' err=' . one_line($resp['err'] ?? '', 120) . ' body=' . one_line($resp['body'] ?? '', 200));
                log_ownerfix_trace('OWNER_SYNC_ERROR_MEMO_NOT_SAVED', [
                    'run_id' => $GLOBALS['run_id'],
                    'clave' => $clave,
                    'opp' => $oppId,
                    'target_owner' => $targetOwnerId,
                    'http' => (int)$resp['http'],
                    'memo_saved' => false,
                    'err' => one_line($resp['err'] ?? '', 120),
                ]);
            }
        }
    }

    if ($needsStage && $targetStageId !== '') {
        if ($dryRun) {
            $wouldUpdate++; $actions['stage_would']++;
            log_debug($logFile, date('c') . ' DRY GHL_OPP_STAGE_UPDATE clave=' . $clave . ' opp=' . $oppId . ' from=' . ($currentStageId ?: 'null') . ' to=' . $targetStageId);
        } else {
            $resp = ghl_put_opp($oppId, ['pipelineStageId' => $targetStageId]);
            if ((int)$resp['http'] >= 200 && (int)$resp['http'] < 300) {
                $updatesDone++; $actions['stage_applied']++;
                log_line($logFile, date('c') . ' OK GHL_OPP_STAGE_UPDATE clave=' . $clave . ' opp=' . $oppId . ' to=' . $targetStageId);
            } else {
                $errorsGhl++;
                log_line($logFile, date('c') . ' ERROR GHL_OPP_STAGE_UPDATE clave=' . $clave . ' opp=' . $oppId . ' http=' . (int)$resp['http'] . ' err=' . one_line($resp['err'] ?? '', 120) . ' body=' . one_line($resp['body'] ?? '', 200));
            }
        }
    }

    if ($needsCf) {
        if ($dryRun) {
            $wouldUpdate++; $actions['cf_would']++;
            log_debug($logFile, date('c') . ' DRY GHL_CONTACT_CF_UPDATE clave=' . $clave . ' contact=' . $contactId . ' field=' . $GHL_CF_CAPISOFT_STAGE_ID . ' value=' . one_line($targetCfValue, 120));
        } else {
            $resp = ghl_put_contact($contactId, ['customFields' => [['id'=>$GHL_CF_CAPISOFT_STAGE_ID,'value'=>$targetCfValue]]]);
            if ((int)$resp['http'] >= 200 && (int)$resp['http'] < 300) {
                $updatesDone++; $actions['cf_applied']++;
                log_line($logFile, date('c') . ' OK GHL_CONTACT_CF_UPDATE clave=' . $clave . ' contact=' . $contactId . ' field=' . $GHL_CF_CAPISOFT_STAGE_ID);
            } else {
                $errorsGhl++;
                log_line($logFile, date('c') . ' ERROR GHL_CONTACT_CF_UPDATE clave=' . $clave . ' contact=' . $contactId . ' http=' . (int)$resp['http'] . ' err=' . one_line($resp['err'] ?? '', 120) . ' body=' . one_line($resp['body'] ?? '', 200));
            }
        }
    }

    $resTrace['decision'] = $dryRun ? 'would_update' : 'applied_update';
    log_resolution($logFile, $resTrace);
    $stateEntry = $current;
    if (!empty($syncMeta)) {
        $stateEntry['_sync'] = $syncMeta;
    }
    $state['by_clave'][$clave] = $stateEntry;
    $idx++; $itemsProcessed++;
}

if ($idx >= $totalFiltered) {
    $idx = 0;
    $stopReason = 'completed_batch';
    $fullPassCompleted = true;
    $progress['full_passes'] = (int)($progress['full_passes'] ?? 0) + 1;
}

$state['last_run_at'] = date('c');
$state['meta'] = [
    'dry_run_last' => $dryRun,
    'version' => 'stage_prod_v1_owner_memo_trace_exp',
    'batch_size_last' => $batchSize,
    'max_ghl_requests_last' => $maxGhlRequests,
    'last_stop_reason' => $stopReason,
    'items_processed_last' => $itemsProcessed,
    'ghl_requests_used_last' => api_budget_used(),
    'log_mode_last' => $logMode,
];
$progress['next_index'] = $idx;
$progress['last_clave'] = $lastClave;
$progress['last_run_at'] = date('c');
$progress['last_stop_reason'] = $stopReason;

write_json_file($stateFile, $state);
write_json_file($progressFile, $progress);

$elapsedMs = (int)round((microtime(true) - $runStarted) * 1000);
log_ownerfix_trace('OWNERFIX_RUN_END', [
    'run_id' => $GLOBALS['run_id'],
    'stop_reason' => $stopReason,
    'start_index' => $startIndex,
    'next_index' => $idx,
    'last_clave' => $lastClave,
    'items_processed' => $itemsProcessed,
    'state_entries_before' => $stateEntriesBefore,
    'state_entries_after' => count($state['by_clave']),
    'full_passes_before' => $fullPassesBefore,
    'full_passes_after' => (int)($progress['full_passes'] ?? 0),
    'full_pass_completed' => $fullPassCompleted,
    'opp_owner_applied' => (int)($actions['opp_owner_applied'] ?? 0),
    'opp_owner_would' => (int)($actions['opp_owner_would'] ?? 0),
    'skipped_aligned' => $skippedAligned,
    'deferred_rate_limit' => $deferredRateLimit,
    'errors_ghl' => $errorsGhl,
    'ghl_requests' => api_budget_used(),
    'elapsed_ms' => $elapsedMs,
]);
log_line($logFile, date('c') . ' RUN_END run_id=' . $GLOBALS['run_id']
    . ' stop_reason=' . $stopReason
    . ' next_index=' . $idx
    . ' last_clave=' . ($lastClave ?? 'null')
    . ' items_processed=' . $itemsProcessed
    . ' state_before=' . $stateEntriesBefore
    . ' state_after=' . count($state['by_clave'])
    . ' changes_found=' . $changesFound
    . ' updates_done=' . $updatesDone
    . ' would_update=' . $wouldUpdate
    . ' skipped_no_map=' . $skippedNoMap
    . ' skipped_no_owner_map=' . $skippedNoOwnerMap
    . ' skipped_no_contact=' . $skippedNoContact
    . ' skipped_no_opp=' . $skippedNoOpp
    . ' skipped_aligned=' . $skippedAligned
    . ' deferred_rate_limit=' . $deferredRateLimit
    . ' deferred_lookup_http_error=' . $deferredLookupHttpError
    . ' errors_ghl=' . $errorsGhl
    . ' ghl_requests=' . api_budget_used()
    . ' elapsed_ms=' . $elapsedMs
);

flock($lockFp, LOCK_UN);
fclose($lockFp);

echo json_encode([
    'ok' => true,
    'dry_run' => $dryRun,
    'proyecto_id' => $projectId,
    'since_date' => $sinceDate,
    'batch_size' => $batchSize,
    'max_ghl_requests' => $maxGhlRequests,
    'start_index' => $startIndex,
    'next_index' => $idx,
    'last_processed_index' => ($idx === 0 && $fullPassCompleted) ? max(0, $totalFiltered - 1) : ($idx > 0 ? $idx - 1 : null),
    'last_processed_clave' => $lastClave,
    'stop_reason' => $stopReason,
    'full_pass_completed' => $fullPassCompleted,
    'total_capisoft' => $snapshot['total_raw'] ?? null,
    'observed_created_since' => $totalFiltered,
    'state_entries_before' => $stateEntriesBefore,
    'state_entries_after' => count($state['by_clave']),
    'changes_found' => $changesFound,
    'updates_done' => $updatesDone,
    'would_update_count' => $wouldUpdate,
    'skipped_no_map' => $skippedNoMap,
    'skipped_no_owner_map' => $skippedNoOwnerMap,
    'skipped_no_contact' => $skippedNoContact,
    'skipped_no_opp' => $skippedNoOpp,
    'skipped_aligned' => $skippedAligned,
    'skipped_reopen_not_allowed' => 0,
    'deferred_rate_limit' => $deferredRateLimit,
    'deferred_lookup_http_error' => $deferredLookupHttpError,
    'errors_ghl' => $errorsGhl,
    'actions' => $actions,
    'elapsed_ms' => $elapsedMs,
    'changes' => [],
    'api_calls' => [
        'contacts_search' => (int)$GLOBALS['api_calls_contacts_search'],
        'contacts_get' => (int)$GLOBALS['api_calls_contacts_get'],
        'opps_search' => 0,
        'opps_get' => 0,
        'put_opp' => (int)$GLOBALS['api_calls_put_opp'],
        'put_contact' => (int)$GLOBALS['api_calls_put_contact'],
        'total' => api_budget_used(),
    ],
    'rate_limit_meta' => null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
