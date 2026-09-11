<?php
// ============================================
// CAPISoft -> GHL Stage Sync (CHILPANCINGO) Cron Job [V2 - batched]
// - proyecto_id = 5 (CHILPANCINGO)
// - since_date filter by CAPISoft created_at
// - state file + shared lock file
// - match GHL contact by normalized email(s), fallback phone
// - SIMPLE RULE: if counterpart is not found in GHL, skip immediately and do not attempt any further reconciliation
// - update owner: contact and opportunity
// - update opportunity stage + contact customField capisoft_stage
// - RE-OPEN RULE: if CAPISoft is OPEN stage and GHL status is abandoned -> set status open
// - DO NOT TOUCH: if GHL status is won/lost (skip to avoid mixing closed histories)
// - DRY RUN default = 1 (no writes to GHL)
// - BATCH MODE: process by blocks with checkpoint + request budget to avoid 429
//
// NOTES:
// - Apartado CAPISoft etapa_id is still pending. Keep it intentionally out of the map for now.
// - This script assumes CAPISoft is source of truth for shared stage + assigned owner.
// - Terminal status logic (won/lost/abandoned) remains in a separate cron.
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// ====== CONFIG ======
$defaultProyectoId       = 5;
$defaultSinceDate        = '2025-12-30'; // YYYY-MM-DD
$defaultDryRun           = true;
$defaultBatchSize        = 20;           // max records processed per run
$defaultMaxGhlRequests   = 25;           // conservative budget to avoid 429
$defaultStartOver        = false;
$defaultApiTrace         = true;         // log every API request/response summary
$defaultApiTraceBodies   = true;         // include truncated request/response bodies
$defaultApiTraceMaxChars = 500;          // truncate long payloads/bodies
$defaultResolutionTrace  = true;         // log per-clave resolution summary
$defaultResolutionMaxChars = 800;        // truncate resolution trace payload

// CAPISoft
$CAPISOFT_BASE  = "https://api-3.capisoftware.com.mx/eu/capi-b/public/api/v2/ventas/oportunidades";
$CAPISOFT_TOKEN = getenv('CAPISOFT_TOKEN') ?: 'CAPISOFT_TOKEN';

// GHL (LeadConnector API v2)
$GHL_BASE_URL = "https://services.leadconnectorhq.com";
$GHL_API_VER  = "2021-07-28";
$GHL_TOKEN    = getenv('GHL_TOKEN') ?: 'GHL_TOKEN';

// CHILPANCINGO location + pipeline
$GHL_LOCATION_ID = "2cOAVW7auz2agTWyCnxF";
$GHL_PIPELINE_ID = "mBsz4BC5Yw9yVf9cfdZj"; // CHILPANCINGO - Flujo de venta

// Custom field ID in GHL for capisoft_stage
$GHL_CF_CAPISOFT_STAGE_ID = "9akn1HKwx4LzwwKING1w";

// CAPISoft etapa_id -> GHL pipelineStageId (CHILPANCINGO)
// NOTE: Apartado is intentionally missing until its CAPISoft etapa_id is confirmed.
$CAPI_TO_GHL_STAGE = [
    61  => 'd9d6834c-c3c4-45b0-bdb4-925f08c9d942', // Asignado
    234 => 'b3bd8bd1-9b80-4b47-b402-161dc2e1d4e4', // Buscando contacto CAPI
    236 => '6b538a94-3960-4219-965d-7e58bd2bc3bc', // Enfriado
    345 => 'a3d1f534-ce3b-478a-90d8-f019f46ef6ce', // Nurture
    62  => '04204bfd-ccb8-4c46-9d60-9a6c03e42917', // Seguimiento
    235 => '08e6a562-c13e-4219-b711-c883e0f2073f', // Negociación
    // Terminales (64,65,66) se manejan en el cron de status terminales.
    // TODO Apartado cuando se confirme etapa_id CAPISoft.
];

$CAPI_OPEN_STAGE_IDS = [61, 234, 236, 345, 62, 235];

// Derived from current middleware mapping + GHL /users lookup.
$RESPONSABLE_ID_TO_GHL_USER_ID = [
    63  => 'wiEh4slRyJ4kcTrvmbaX', // Juan Arceo
    65  => 'PCmfjjFzA0M7T0FJeHJS', // Raúl Santiago
    141 => 'rmnpri2YtCeVlkjwMKk8', // Denisse Jalife
    142 => 'VFd98tdUzrwHPFw71bZW', // Sofía Santos
    233 => 'XJFKjmrZwSILCLAxIA3U', // Diana Gonzalez
    // TODO si aplica:
    // 173 => '...', // María Preciado
    // 230 => '...', // prueba correo capi
    // 50  => '...', // Juan Franco
];

// Contadores API
$GLOBALS['api_calls_contacts_search'] = 0;
$GLOBALS['api_calls_contacts_get']    = 0;
$GLOBALS['api_calls_opps_search']     = 0;
$GLOBALS['api_calls_opps_get']        = 0;
$GLOBALS['api_calls_put_opp']         = 0;
$GLOBALS['api_calls_put_contact']     = 0;
$GLOBALS['api_trace_enabled']         = false;
$GLOBALS['api_trace_bodies_enabled']  = true;
$GLOBALS['api_trace_max_chars']       = 500;
$GLOBALS['api_trace_log_file']        = null;
$GLOBALS['resolution_trace_enabled'] = false;
$GLOBALS['resolution_trace_max_chars'] = 800;

// ====== CLI ARGS ======
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
    if (empty($GLOBALS['resolution_trace_enabled'])) {
        return;
    }
    $max = (int) ($GLOBALS['resolution_trace_max_chars'] ?? 800);
    log_line($file, date('c') . " RESOLUTION " . one_line($data, $max));
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
            $line .= " curl_err=" . str_replace(["
", "
"], ' ', $err);
        }
        if ($traceBodies) {
            if ($requestBody !== null) {
                $req = substr(str_replace(["
", "
"], ' ', $requestBody), 0, $traceMax);
                $line .= " req={$req}";
            }
            if ($body !== false && $body !== null && $body !== '') {
                $resp = substr(str_replace(["
", "
"], ' ', (string) $body), 0, $traceMax);
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

function pick_string($value)
{
    if (is_string($value)) {
        $v = trim($value);
        return $v === '' ? null : $v;
    }
    if (is_array($value)) {
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return trim($item);
            }
            if (is_array($item)) {
                foreach (['email', 'telefono', 'telefono1', 'phone', 'value'] as $k) {
                    if (!empty($item[$k]) && is_string($item[$k])) {
                        return trim($item[$k]);
                    }
                }
            }
        }
    }
    return null;
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

function resolve_capi_responsable_id(array $o)
{
    foreach (['responsable_id', 'responsableId', 'id_responsable', 'responsableID'] as $key) {
        if (isset($o[$key]) && $o[$key] !== '' && $o[$key] !== null) {
            return (int) $o[$key];
        }
    }
    return null;
}

function extract_owner_id($entity)
{
    if (!is_array($entity)) {
        return null;
    }
    foreach (['assignedTo', 'assigned_to', 'ownerId', 'owner_id', 'assignedUserId'] as $key) {
        if (!empty($entity[$key]) && is_string($entity[$key])) {
            return $entity[$key];
        }
    }
    return null;
}

function get_cf_value($contact, $customFieldId)
{
    $cfs = $contact['customFields'] ?? null;
    if (!is_array($cfs)) {
        return null;
    }
    foreach ($cfs as $cf) {
        if (($cf['id'] ?? null) === $customFieldId) {
            return $cf['value'] ?? null;
        }
    }
    return null;
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

function ghl_find_opp_by_contact_pipeline($ghlBase, $headers, $locationId, $pipelineId, $contactId, $logFile = null, $clave = null)
{
    $attempts = [
        [
            'locationId' => $locationId,
            'page'       => 1,
            'limit'      => 10,
            'filters'    => [
                ['field' => 'contactId', 'operator' => 'eq', 'value' => $contactId],
                ['field' => 'pipelineId', 'operator' => 'eq', 'value' => $pipelineId],
            ],
        ],
        [
            'locationId' => $locationId,
            'page'       => 1,
            'limit'      => 10,
            'filters'    => [
                ['field' => 'contact_id', 'operator' => 'eq', 'value' => $contactId],
                ['field' => 'pipeline_id', 'operator' => 'eq', 'value' => $pipelineId],
            ],
        ],
    ];

    foreach ($attempts as $idx => $payload) {
        $GLOBALS['api_calls_opps_search']++;
        [$http, $body, $err] = http_json('POST', $ghlBase . '/opportunities/search', $headers, $payload, 25);

        if (is_rate_limit_response($http, $body)) {
            if ($logFile) {
                $snippet = substr((string) $body, 0, 300);
                log_line($logFile, date('c') . " RATE_LIMIT GHL_OPP_SEARCH_POST clave={$clave} contact={$contactId} http={$http} body={$snippet}");
            }
            return [null, ['reason' => 'rate_limit', 'http' => $http, 'attempt' => $idx]];
        }

        if ($err || $http >= 400) {
            if ($logFile) {
                $snippet = substr((string) $body, 0, 300);
                log_line($logFile, date('c') . " ERROR GHL_OPP_SEARCH_POST clave={$clave} contact={$contactId} http={$http} err={$err} body={$snippet}");
            }
            return [null, ['reason' => 'http_error', 'http' => $http, 'err' => $err, 'attempt' => $idx]];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [null, ['reason' => 'parse_error', 'http' => $http, 'attempt' => $idx]];
        }
        $list = $json['opportunities'] ?? ($json['data'] ?? null);
        if (is_array($list) && count($list) > 0) {
            return [$list[0], ['reason' => 'ok', 'http' => $http, 'attempt' => $idx]];
        }
    }

    return [null, ['reason' => 'empty', 'http' => 200]];
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

function ghl_get_contact($ghlBase, $headers, $contactId, $logFile = null, $clave = null)
{
    $GLOBALS['api_calls_contacts_get']++;
    [$http, $body, $err] = http_json('GET', $ghlBase . '/contacts/' . urlencode($contactId), $headers, null, 25);
    if (is_rate_limit_response($http, $body)) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " RATE_LIMIT GHL_CONTACT_GET clave={$clave} contact={$contactId} http={$http} body={$snippet}");
        }
        return [$http, $body, $err, true];
    }
    return [$http, $body, $err, false];
}

function ghl_get_opportunity($ghlBase, $headers, $opportunityId, $logFile = null, $clave = null)
{
    $GLOBALS['api_calls_opps_get']++;
    [$http, $body, $err] = http_json('GET', $ghlBase . '/opportunities/' . urlencode($opportunityId), $headers, null, 25);
    if (is_rate_limit_response($http, $body)) {
        if ($logFile) {
            $snippet = substr((string) $body, 0, 300);
            log_line($logFile, date('c') . " RATE_LIMIT GHL_OPP_GET clave={$clave} opp={$opportunityId} http={$http} body={$snippet}");
        }
        return [$http, $body, $err, true];
    }
    return [$http, $body, $err, false];
}

function ghl_update_opportunity_stage($ghlBase, $headers, $opportunityId, $pipelineStageId)
{
    $GLOBALS['api_calls_put_opp']++;
    return http_json('PUT', $ghlBase . '/opportunities/' . urlencode($opportunityId), $headers, ['pipelineStageId' => $pipelineStageId], 25);
}

function ghl_update_opportunity_status($ghlBase, $headers, $opportunityId, $status)
{
    $GLOBALS['api_calls_put_opp']++;
    return http_json('PUT', $ghlBase . '/opportunities/' . urlencode($opportunityId), $headers, ['status' => $status], 25);
}

function ghl_update_opportunity_owner($ghlBase, $headers, $opportunityId, $ownerId)
{
    $GLOBALS['api_calls_put_opp']++;
    return http_json('PUT', $ghlBase . '/opportunities/' . urlencode($opportunityId), $headers, ['assignedTo' => $ownerId], 25);
}

function ghl_update_contact_owner($ghlBase, $headers, $contactId, $ownerId)
{
    $GLOBALS['api_calls_put_contact']++;
    return http_json('PUT', $ghlBase . '/contacts/' . urlencode($contactId), $headers, ['assignedTo' => $ownerId], 25);
}

function ghl_update_contact_capisoft_stage($ghlBase, $headers, $contactId, $customFieldId, $value)
{
    $GLOBALS['api_calls_put_contact']++;
    $payload = [
        'customFields' => [
            ['id' => $customFieldId, 'value' => $value],
        ],
    ];
    return http_json('PUT', $ghlBase . '/contacts/' . urlencode($contactId), $headers, $payload, 25);
}

function is_open_stage($etapaId, $openStageIds)
{
    return in_array((int) $etapaId, $openStageIds, true);
}

// ====== INPUTS ======
$proyectoId        = $defaultProyectoId;
$sinceDate         = $defaultSinceDate;
$dryRun            = $defaultDryRun;
$batchSize         = $defaultBatchSize;
$maxGhlRequests    = $defaultMaxGhlRequests;
$startOver         = $defaultStartOver;
$apiTrace          = get_bool_arg('api_trace', $defaultApiTrace);
$apiTraceBodies    = get_bool_arg('api_trace_bodies', $defaultApiTraceBodies);
$apiTraceMaxChars  = get_int_arg('api_trace_max_chars', $defaultApiTraceMaxChars);
$resolutionTrace   = get_bool_arg('resolution_trace', $defaultResolutionTrace);
$resolutionMaxChars= get_int_arg('resolution_trace_max_chars', $defaultResolutionMaxChars);

$p = get_arg('proyecto_id');
$s = get_arg('since_date');
$b = get_arg('batch_size');
$m = get_arg('max_ghl_requests');

if ($p !== null) {
    $proyectoId = (int) $p;
}
if ($s !== null) {
    $sinceDate = (string) $s;
}
if ($b !== null) {
    $batchSize = max(1, (int) $b);
}
if ($m !== null) {
    $maxGhlRequests = max(1, (int) $m);
}
$dryRun    = get_bool_arg('dry_run', $defaultDryRun);
$startOver = get_bool_arg('start_over', $defaultStartOver);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceDate)) {
    echo json_encode(['ok' => false, 'error' => 'since_date inválido, usa YYYY-MM-DD']);
    exit(1);
}
if ($proyectoId !== 5) {
    echo json_encode(['ok' => false, 'error' => 'Este script está fijo para CHILPANCINGO (proyecto_id=5).']);
    exit(1);
}
if (!$GHL_TOKEN || $GHL_TOKEN === 'GHL_TOKEN') {
    echo json_encode(['ok' => false, 'error' => 'Configura GHL_TOKEN real vía env o constante.']);
    exit(1);
}

// ====== FILES / FOLDERS ======
$storageDir = __DIR__ . '/storage';
$logsDir    = __DIR__ . '/logs';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}

$stateFile    = $storageDir . '/capisoft_state_project_' . $proyectoId . '_stage_productive.json';
$progressFile = $storageDir . '/capisoft_progress_project_' . $proyectoId . '_stage_productive.json';
$logFile      = $logsDir . '/capisoft_sync_chilpancingo_stage_productive.log';
if (!file_exists($logFile)) {
    touch($logFile);
}

$GLOBALS['api_trace_enabled']          = $apiTrace;
$GLOBALS['api_trace_bodies_enabled']   = $apiTraceBodies;
$GLOBALS['api_trace_max_chars']        = $apiTraceMaxChars;
$GLOBALS['api_trace_log_file']         = $logFile;
$GLOBALS['resolution_trace_enabled']   = $resolutionTrace;
$GLOBALS['resolution_trace_max_chars'] = $resolutionMaxChars;

log_line($logFile, date('c') . " RUN_START dry_run=" . ($dryRun ? '1' : '0') . " since_date={$sinceDate} batch_size={$batchSize} max_ghl_requests={$maxGhlRequests} start_over=" . ($startOver ? '1' : '0') . " api_trace=" . ($apiTrace ? '1' : '0') . " resolution_trace=" . ($resolutionTrace ? '1' : '0'));
$lockFile     = $storageDir . '/capisoft_lock_project_' . $proyectoId . '.lock'; // shared with terminal cron

// ====== LOCK ======
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit(0);
}

// ====== RUN ======
$startedAt  = microtime(true);
$ghlHeaders = ghl_headers($GHL_TOKEN, $GHL_API_VER);

$state = read_state($stateFile);
if (!isset($state['by_clave']) || !is_array($state['by_clave'])) {
    $state['by_clave'] = [];
}
$beforeCount = count($state['by_clave']);

$progress = read_progress($progressFile);
if ($startOver) {
    $progress = [
        'next_index'       => 0,
        'last_clave'       => null,
        'last_run_at'      => null,
        'last_stop_reason' => 'manual_reset',
        'full_passes'      => (int) ($progress['full_passes'] ?? 0),
    ];
}

$CAPISOFT_URL = $CAPISOFT_BASE . '?proyecto_id=' . $proyectoId;
[$http, $body, $err] = capi_fetch_opps($CAPISOFT_URL, $CAPISOFT_TOKEN);
if ($err || $http >= 400) {
    log_line($logFile, date('c') . " ERROR CAPI GET proyecto_id={$proyectoId} http={$http} err={$err}");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    echo json_encode(['ok' => false, 'http' => $http, 'error' => $err ?: 'CAPISoft error']);
    exit(1);
}

$json = json_decode($body, true);
$data = $json['data'] ?? null;
if (!is_array($data)) {
    log_line($logFile, date('c') . " ERROR CAPI PARSE proyecto_id={$proyectoId} body_unexpected");
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    echo json_encode(['ok' => false, 'error' => 'Respuesta inesperada de CAPISoft']);
    exit(1);
}

usort($data, function ($a, $b) {
    return (int) ($a['clave'] ?? 0) <=> (int) ($b['clave'] ?? 0);
});

$sinceTs = to_ts($sinceDate . ' 00:00:00');
$filtered = [];
foreach ($data as $o) {
    $createdAt = $o['created_at'] ?? null;
    $createdTs = to_ts($createdAt);
    if ($createdTs !== null && $createdTs >= $sinceTs) {
        $filtered[] = $o;
    }
}

$totalFiltered = count($filtered);
$startIndex = (int) ($progress['next_index'] ?? 0);
if ($startIndex < 0 || $startIndex >= $totalFiltered) {
    $startIndex = 0;
}

$changesFound             = 0;
$updatesDone              = 0;
$wouldUpdateCount         = 0;
$skippedNoMap             = 0;
$skippedNoOwnerMap        = 0;
$skippedNoContact         = 0; // real no-match only
$skippedNoOpp             = 0;
$skippedAligned           = 0;
$skippedReopenNotAllowed  = 0;
$errorsGhl                = 0;
$deferredRateLimit        = 0;
$deferredLookupHttpError  = 0;
$reopenApplied            = 0;
$reopenWouldApply         = 0;
$contactOwnerApplied      = 0;
$contactOwnerWouldApply   = 0;
$oppOwnerApplied          = 0;
$oppOwnerWouldApply       = 0;
$stageApplied             = 0;
$stageWouldApply          = 0;
$cfApplied                = 0;
$cfWouldApply             = 0;
$changes                  = [];
$itemsProcessedThisRun    = 0;
$lastProcessedIndex       = null;
$lastProcessedClave       = null;
$stopReason               = 'completed_batch';
$fullPassCompleted        = false;
$rateLimitMeta            = null;

for ($i = $startIndex; $i < $totalFiltered; $i++) {
    if ($itemsProcessedThisRun >= $batchSize) {
        $stopReason = 'batch_limit';
        break;
    }
    if (ghl_requests_used() >= $maxGhlRequests) {
        $stopReason = 'request_budget';
        break;
    }

    $o = $filtered[$i];
    $clave = (string) ($o['clave'] ?? '');
    if ($clave === '') {
        log_line($logFile, date('c') . " SKIP EMPTY_CLAVE index={$i}");
        $lastProcessedIndex = $i;
        $itemsProcessedThisRun++;
        continue;
    }

    $capEtapaId       = (int) ($o['etapa_id'] ?? 0);
    $capEtapa         = $o['etapa'] ?? null;
    $capResponsableId = resolve_capi_responsable_id($o);
    $rawEmail         = pick_string($o['emails'] ?? null);
    $emailCandidates  = email_candidates((string) $rawEmail);
    $primaryEmail     = !empty($emailCandidates) ? $emailCandidates[0] : null;
    $phone            = normalize_phone(pick_string($o['telefonos'] ?? null));

    $current = [
        'etapa_id'       => $capEtapaId,
        'etapa'          => $capEtapa,
        'updated_by'     => $o['updated_by'] ?? null,
        'created_at'     => $o['created_at'] ?? null,
        'updated_at'     => $o['updated_at'] ?? ($o['created_at'] ?? null),
        'responsable'    => $o['responsable'] ?? null,
        'responsable_id' => $capResponsableId,
        'emails'         => $rawEmail,
        'telefonos'      => $phone,
        'id'             => $o['id'] ?? null,
    ];

    $resolution = [
        'clave' => $clave,
        'email_original' => $rawEmail,
        'emails_tried' => $emailCandidates,
        'phone_original' => $phone,
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
    ];

    $prev = $state['by_clave'][$clave] ?? null;
    $isFirstSeen = !$prev;
    $resolution['first_seen'] = $isFirstSeen;
    if ($isFirstSeen) {
        log_line($logFile, date('c') . " SEED FIRST_SEEN clave={$clave} etapa_id={$capEtapaId} etapa=\"{$capEtapa}\" email=" . ($primaryEmail ?? '') . " phone={$phone} responsable_id=" . ($capResponsableId ?? ''));
        $state['by_clave'][$clave] = $current;
        $prev = $current;
    }

    $changed = ($prev['etapa_id'] ?? null) != $capEtapaId
        || ($prev['etapa'] ?? null) != $capEtapa
        || ($prev['responsable_id'] ?? null) != $capResponsableId;

    $targetStageId = $CAPI_TO_GHL_STAGE[$capEtapaId] ?? null;
    $isOpenCapStage = is_open_stage($capEtapaId, $CAPI_OPEN_STAGE_IDS);
    if (!$targetStageId) {
        if ($changed || !$isOpenCapStage) {
            $changesFound++;
            $skippedNoMap++;
            $resolution['decision'] = $isOpenCapStage ? 'no_stage_map' : 'skip_not_open_stage';
            log_line($logFile, date('c') . " SKIP NO_MAP clave={$clave} etapa_id={$capEtapaId} etapa=\"{$capEtapa}\" decision=" . $resolution['decision']);
            log_resolution($logFile, $resolution);
        }
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    [$contact, $opp, $meta] = ghl_find_contact_and_opp(
        $GHL_BASE_URL,
        $ghlHeaders,
        $GHL_LOCATION_ID,
        $GHL_PIPELINE_ID,
        (string) $rawEmail,
        $phone,
        $logFile,
        $clave
    );

    if (!empty($meta['lookup']['attempts']) && is_array($meta['lookup']['attempts'])) {
        foreach ($meta['lookup']['attempts'] as $attempt) {
            if (($attempt['channel'] ?? null) === 'email') {
                $resolution['email_search_http'] = $attempt['http'] ?? $resolution['email_search_http'];
                $resolution['email_search_count'] = $attempt['count'] ?? $resolution['email_search_count'];
            } elseif (($attempt['channel'] ?? null) === 'phone') {
                $resolution['phone_tried'] = $attempt['value'] ?? $phone;
                $resolution['phone_search_http'] = $attempt['http'] ?? $resolution['phone_search_http'];
                $resolution['phone_search_count'] = $attempt['count'] ?? $resolution['phone_search_count'];
            }
        }
    }
    $resolution['matched_by'] = $meta['matched_by'] ?? ($meta['lookup']['matched_by'] ?? null);
    $resolution['matched_value'] = $meta['matched_value'] ?? ($meta['lookup']['matched_value'] ?? null);

    if (!$contact) {
        $reason = $meta['reason'] ?? 'unknown';

        if ($reason === 'rate_limit') {
            $deferredRateLimit++;
            $stopReason = 'rate_limit_contact_lookup';
            $rateLimitMeta = ['clave' => $clave, 'http' => $meta['http'] ?? null, 'stage' => 'contact_lookup'];
            $resolution['decision'] = 'lookup_rate_limited';
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} email=" . ($primaryEmail ?? '') . " phone={$phone}");
            log_resolution($logFile, $resolution);
            break; // retry same clave on next run
        }

        if ($reason === 'http_error') {
            $deferredLookupHttpError++;
            $resolution['decision'] = 'lookup_http_error';
            log_line($logFile, date('c') . " DEFER LOOKUP_HTTP_ERROR clave={$clave} email=" . ($primaryEmail ?? '') . " phone={$phone} http=" . ($meta['http'] ?? '') . " err=" . ($meta['err'] ?? ''));
            log_resolution($logFile, $resolution);
            $lastProcessedIndex = $i;
            $lastProcessedClave = $clave;
            $itemsProcessedThisRun++;
            continue;
        }

        if ($changed || !$isOpenCapStage) {
            $changesFound++;
        }
        $skippedNoContact++;
        $resolution['decision'] = 'skip_no_contact';
        log_line($logFile, date('c') . " SKIP NO_CONTACT reason={$reason} clave={$clave} email=" . ($primaryEmail ?? '') . " phone={$phone}");
        log_resolution($logFile, $resolution);
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    $contactId = $contact['id'] ?? null;
    $resolution['contact_id'] = $contactId;
    if (!$contactId) {
        if ($changed || !$isOpenCapStage) {
            $changesFound++;
        }
        $skippedNoContact++;
        $resolution['decision'] = 'skip_no_contact';
        log_line($logFile, date('c') . " SKIP NO_CONTACT clave={$clave} reason=missing_contact_id email=" . ($primaryEmail ?? ''));
        log_resolution($logFile, $resolution);
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    if (!$opp || !is_array($opp)) {
        [$opp2, $m2] = ghl_find_opp_by_contact_pipeline(
            $GHL_BASE_URL,
            $ghlHeaders,
            $GHL_LOCATION_ID,
            $GHL_PIPELINE_ID,
            $contactId,
            $logFile,
            $clave
        );

        if (!$opp2 || !is_array($opp2)) {
            $r2 = $m2['reason'] ?? 'unknown';
            if ($r2 === 'rate_limit') {
                $deferredRateLimit++;
                $stopReason = 'rate_limit_opp_lookup';
                $rateLimitMeta = ['clave' => $clave, 'http' => $m2['http'] ?? null, 'stage' => 'opp_lookup'];
                $resolution['decision'] = 'opp_lookup_rate_limited';
                log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} contact={$contactId} stage=opp_lookup");
                log_resolution($logFile, $resolution);
                break;
            }
            if ($r2 === 'http_error') {
                $deferredLookupHttpError++;
                $resolution['decision'] = 'opp_lookup_http_error';
                log_line($logFile, date('c') . " DEFER LOOKUP_HTTP_ERROR clave={$clave} contact={$contactId} stage=opp_lookup http=" . ($m2['http'] ?? '') . " err=" . ($m2['err'] ?? ''));
                log_resolution($logFile, $resolution);
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }

            if ($changed || !$isOpenCapStage) {
                $changesFound++;
            }
            $skippedNoOpp++;
            $resolution['decision'] = 'skip_no_opp';
            log_line($logFile, date('c') . " SKIP NO_OPP reason={$r2} clave={$clave} contact={$contactId} pipeline={$GHL_PIPELINE_ID}");
            log_resolution($logFile, $resolution);
            $state['by_clave'][$clave] = $current;
            $lastProcessedIndex = $i;
            $lastProcessedClave = $clave;
            $itemsProcessedThisRun++;
            continue;
        }
        $opp = $opp2;
    }

    $oppId          = $opp['id'] ?? null;
    $resolution['opp_id'] = $oppId;
    $currentStageId = $opp['pipelineStageId'] ?? null;
    $currentStatus  = $opp['status'] ?? null;

    if (!$oppId) {
        if ($changed || !$isOpenCapStage) {
            $changesFound++;
        }
        $skippedNoOpp++;
        $resolution['decision'] = 'skip_no_opp';
        log_line($logFile, date('c') . " SKIP NO_OPP clave={$clave} reason=missing_opp_id contact={$contactId}");
        log_resolution($logFile, $resolution);
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    $targetOwnerId = $capResponsableId ? ($RESPONSABLE_ID_TO_GHL_USER_ID[$capResponsableId] ?? null) : null;
    if ($capResponsableId && !$targetOwnerId) {
        if ($changed || !$isOpenCapStage) {
            $changesFound++;
        }
        $skippedNoOwnerMap++;
        log_line($logFile, date('c') . " SKIP NO_OWNER_MAP clave={$clave} responsable_id={$capResponsableId} responsable=\"" . ($current['responsable'] ?? '') . "\"");
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    $currentContactOwnerId = extract_owner_id($contact);
    if (!$currentContactOwnerId) {
        [$ch, $cb, $ce, $cRate] = ghl_get_contact($GHL_BASE_URL, $ghlHeaders, $contactId, $logFile, $clave);
        if ($cRate) {
            $deferredRateLimit++;
            $stopReason = 'rate_limit_contact_get';
            $rateLimitMeta = ['clave' => $clave, 'http' => $ch, 'stage' => 'contact_get'];
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} contact={$contactId} stage=contact_get");
            break;
        }
        if (!$ce && $ch < 400) {
            $contactDetails = json_decode($cb, true);
            if (is_array($contactDetails)) {
                $currentContactOwnerId = extract_owner_id($contactDetails['contact'] ?? $contactDetails);
            }
        }
    }

    $currentOppOwnerId = extract_owner_id($opp);
    if (!$currentOppOwnerId) {
        [$oh0, $ob0, $oe0, $oRate] = ghl_get_opportunity($GHL_BASE_URL, $ghlHeaders, $oppId, $logFile, $clave);
        if ($oRate) {
            $deferredRateLimit++;
            $stopReason = 'rate_limit_opp_get';
            $rateLimitMeta = ['clave' => $clave, 'http' => $oh0, 'stage' => 'opp_get'];
            log_line($logFile, date('c') . " DEFER RATE_LIMIT clave={$clave} opp={$oppId} stage=opp_get");
            break;
        }
        if (!$oe0 && $oh0 < 400) {
            $oppDetails = json_decode($ob0, true);
            if (is_array($oppDetails)) {
                $currentOppOwnerId = extract_owner_id($oppDetails['opportunity'] ?? $oppDetails);
            }
        }
    }

    $needsContactOwnerSync = ($targetOwnerId && $currentContactOwnerId !== $targetOwnerId);
    $needsOppOwnerSync     = ($targetOwnerId && $currentOppOwnerId !== $targetOwnerId);
    $needsStageSync        = ($currentStageId !== $targetStageId);

    $capisoftStageValue = $proyectoId . '|' . $capEtapaId . '|' . ($capEtapa ?? '');
    $existingCfValue    = get_cf_value($contact, $GHL_CF_CAPISOFT_STAGE_ID);
    $needsCfSync        = ($existingCfValue !== $capisoftStageValue);

    if ($currentStatus === 'abandoned' && is_open_stage($capEtapaId, $CAPI_OPEN_STAGE_IDS)) {
        if ($dryRun) {
            $reopenWouldApply++;
            $wouldUpdateCount++;
            log_line($logFile, date('c') . " DRY GHL_OPP_REOPEN clave={$clave} opp={$oppId} status=open (from abandoned)");
        } else {
            [$rh, $rb, $re] = ghl_update_opportunity_status($GHL_BASE_URL, $ghlHeaders, $oppId, 'open');
            if ($re || $rh >= 400) {
                $errorsGhl++;
                log_line($logFile, date('c') . " ERROR GHL_OPP_REOPEN clave={$clave} opp={$oppId} http={$rh} err={$re} body=" . substr((string) $rb, 0, 500));
                $state['by_clave'][$clave] = $current;
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }
            $reopenApplied++;
            $updatesDone++;
            $currentStatus = 'open';
            log_line($logFile, date('c') . " OK GHL_OPP_REOPEN clave={$clave} opp={$oppId} status=open (from abandoned)");
        }
    }

    if ($currentStatus === 'lost' || $currentStatus === 'won') {
        $skippedReopenNotAllowed++;
        $resolution['decision'] = 'skip_closed_status_' . $currentStatus;
        log_line($logFile, date('c') . " SKIP REOPEN_NOT_ALLOWED clave={$clave} opp={$oppId} status={$currentStatus} capi_etapa_id={$capEtapaId}");
        log_resolution($logFile, $resolution);
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    if ($changed) {
        $changesFound++;
        $changes[] = [
            'clave'              => $clave,
            'capi_opp_id'        => $current['id'],
            'email'              => $primaryEmail,
            'emails_raw'         => $rawEmail,
            'tel'                => $phone,
            'from'               => [
                'etapa_id'       => $prev['etapa_id'] ?? null,
                'etapa'          => $prev['etapa'] ?? null,
                'responsable_id' => $prev['responsable_id'] ?? null,
                'responsable'    => $prev['responsable'] ?? null,
            ],
            'to'                 => [
                'etapa_id'       => $capEtapaId,
                'etapa'          => $capEtapa,
                'responsable_id' => $capResponsableId,
                'responsable'    => $current['responsable'],
            ],
            'ghl'                => [
                'contact_id'             => $contactId,
                'opp_id'                 => $oppId,
                'current_stage_id'       => $currentStageId,
                'target_stage_id'        => $targetStageId,
                'current_contact_owner'  => $currentContactOwnerId,
                'current_opp_owner'      => $currentOppOwnerId,
                'target_owner_id'        => $targetOwnerId,
                'needs_contact_owner'    => $needsContactOwnerSync,
                'needs_opp_owner'        => $needsOppOwnerSync,
                'needs_stage'            => $needsStageSync,
                'needs_cf'               => $needsCfSync,
            ],
        ];
    }

    if (!$needsContactOwnerSync && !$needsOppOwnerSync && !$needsStageSync && !$needsCfSync) {
        $skippedAligned++;
        $resolution['decision'] = 'aligned';
        log_resolution($logFile, $resolution);
        $state['by_clave'][$clave] = $current;
        $lastProcessedIndex = $i;
        $lastProcessedClave = $clave;
        $itemsProcessedThisRun++;
        continue;
    }

    if ($needsContactOwnerSync) {
        if ($dryRun) {
            $contactOwnerWouldApply++;
            $wouldUpdateCount++;
            log_line($logFile, date('c') . " DRY GHL_CONTACT_OWNER_UPDATE clave={$clave} contact={$contactId} owner={$targetOwnerId}");
        } else {
            [$h, $b, $e] = ghl_update_contact_owner($GHL_BASE_URL, $ghlHeaders, $contactId, $targetOwnerId);
            if ($e || $h >= 400) {
                $errorsGhl++;
                log_line($logFile, date('c') . " ERROR GHL_CONTACT_OWNER_UPDATE clave={$clave} contact={$contactId} http={$h} err={$e} body=" . substr((string) $b, 0, 500));
                $state['by_clave'][$clave] = $current;
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }
            $contactOwnerApplied++;
            $updatesDone++;
            log_line($logFile, date('c') . " OK GHL_CONTACT_OWNER_UPDATE clave={$clave} contact={$contactId} owner={$targetOwnerId}");
        }
    }

    if ($needsOppOwnerSync) {
        if ($dryRun) {
            $oppOwnerWouldApply++;
            $wouldUpdateCount++;
            log_line($logFile, date('c') . " DRY GHL_OPP_OWNER_UPDATE clave={$clave} opp={$oppId} owner={$targetOwnerId}");
        } else {
            [$h, $b, $e] = ghl_update_opportunity_owner($GHL_BASE_URL, $ghlHeaders, $oppId, $targetOwnerId);
            if ($e || $h >= 400) {
                $errorsGhl++;
                log_line($logFile, date('c') . " ERROR GHL_OPP_OWNER_UPDATE clave={$clave} opp={$oppId} http={$h} err={$e} body=" . substr((string) $b, 0, 500));
                $state['by_clave'][$clave] = $current;
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }
            $oppOwnerApplied++;
            $updatesDone++;
            log_line($logFile, date('c') . " OK GHL_OPP_OWNER_UPDATE clave={$clave} opp={$oppId} owner={$targetOwnerId}");
        }
    }

    if ($needsStageSync) {
        if ($dryRun) {
            $stageWouldApply++;
            $wouldUpdateCount++;
            log_line($logFile, date('c') . " DRY GHL_OPP_STAGE_UPDATE clave={$clave} opp={$oppId} stage={$targetStageId}");
        } else {
            [$h, $b, $e] = ghl_update_opportunity_stage($GHL_BASE_URL, $ghlHeaders, $oppId, $targetStageId);
            if ($e || $h >= 400) {
                $errorsGhl++;
                log_line($logFile, date('c') . " ERROR GHL_OPP_STAGE_UPDATE clave={$clave} opp={$oppId} http={$h} err={$e} body=" . substr((string) $b, 0, 500));
                $state['by_clave'][$clave] = $current;
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }
            $stageApplied++;
            $updatesDone++;
            log_line($logFile, date('c') . " OK GHL_OPP_STAGE_UPDATE clave={$clave} opp={$oppId} stage={$targetStageId}");
        }
    }

    if ($needsCfSync) {
        if ($dryRun) {
            $cfWouldApply++;
            $wouldUpdateCount++;
            log_line($logFile, date('c') . " DRY GHL_CONTACT_CF_UPDATE clave={$clave} contact={$contactId} capisoft_stage=\"{$capisoftStageValue}\"");
        } else {
            [$h, $b, $e] = ghl_update_contact_capisoft_stage($GHL_BASE_URL, $ghlHeaders, $contactId, $GHL_CF_CAPISOFT_STAGE_ID, $capisoftStageValue);
            if ($e || $h >= 400) {
                $errorsGhl++;
                log_line($logFile, date('c') . " ERROR GHL_CONTACT_CF_UPDATE clave={$clave} contact={$contactId} http={$h} err={$e} body=" . substr((string) $b, 0, 500));
                $state['by_clave'][$clave] = $current;
                $lastProcessedIndex = $i;
                $lastProcessedClave = $clave;
                $itemsProcessedThisRun++;
                continue;
            }
            $cfApplied++;
            $updatesDone++;
            log_line($logFile, date('c') . " OK GHL_CONTACT_CF_UPDATE clave={$clave} contact={$contactId} capisoft_stage=\"{$capisoftStageValue}\"");
        }
    }

    $state['by_clave'][$clave] = $current;
    $lastProcessedIndex = $i;
    $lastProcessedClave = $clave;
    $itemsProcessedThisRun++;
}

if ($rateLimitMeta) {
    $nextIndex = $lastProcessedIndex === null ? $startIndex : max(0, $lastProcessedIndex);
    // retry same clave if rate limit hit mid-record
    if ($lastProcessedClave !== ($rateLimitMeta['clave'] ?? null)) {
        $nextIndex = $lastProcessedIndex !== null ? $lastProcessedIndex + 1 : $startIndex;
    } else {
        $nextIndex = $lastProcessedIndex;
    }
} elseif ($stopReason === 'batch_limit' || $stopReason === 'request_budget') {
    $nextIndex = ($lastProcessedIndex === null) ? $startIndex : min($lastProcessedIndex + 1, $totalFiltered);
} else {
    // completed current pass
    $nextIndex = 0;
    $fullPassCompleted = true;
}

if ($nextIndex >= $totalFiltered) {
    $nextIndex = 0;
    $fullPassCompleted = true;
}

$state['last_run_at'] = date('c');
$state['meta'] = [
    'dry_run_last'           => $dryRun,
    'version'                => 'stage_v2_batched',
    'batch_size_last'        => $batchSize,
    'max_ghl_requests_last'  => $maxGhlRequests,
    'last_stop_reason'       => $stopReason,
    'items_processed_last'   => $itemsProcessedThisRun,
    'ghl_requests_used_last' => ghl_requests_used(),
];
write_state($stateFile, $state);

$progress['next_index'] = $nextIndex;
$progress['last_clave'] = $lastProcessedClave;
$progress['last_run_at'] = date('c');
$progress['last_stop_reason'] = $stopReason;
if ($fullPassCompleted) {
    $progress['full_passes'] = (int) ($progress['full_passes'] ?? 0) + 1;
}
write_progress($progressFile, $progress);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

$elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

log_line($logFile, date('c') . " RUN_END stop_reason={$stopReason} next_index={$nextIndex} last_clave=" . ($lastProcessedClave ?? '') . " items_processed={$itemsProcessedThisRun} state_before={$beforeCount} state_after=" . count($state['by_clave']) . " changes_found={$changesFound} would_update={$wouldUpdateCount} updates_done={$updatesDone} no_contact={$skippedNoContact} no_opp={$skippedNoOpp} no_map={$skippedNoMap} no_owner_map={$skippedNoOwnerMap} aligned={$skippedAligned} deferred_rate_limit={$deferredRateLimit} deferred_http={$deferredLookupHttpError} ghl_requests=" . ghl_requests_used() . " elapsed_ms={$elapsedMs}");

echo json_encode([
    'ok'                         => true,
    'dry_run'                    => $dryRun,
    'proyecto_id'                => $proyectoId,
    'since_date'                 => $sinceDate,
    'batch_size'                 => $batchSize,
    'max_ghl_requests'           => $maxGhlRequests,
    'start_index'                => $startIndex,
    'next_index'                 => $nextIndex,
    'last_processed_index'       => $lastProcessedIndex,
    'last_processed_clave'       => $lastProcessedClave,
    'stop_reason'                => $stopReason,
    'full_pass_completed'        => $fullPassCompleted,
    'total_capisoft'             => count($data),
    'observed_created_since'     => count($filtered),
    'state_entries_before'       => $beforeCount,
    'state_entries_after'        => count($state['by_clave']),
    'changes_found'              => $changesFound,
    'updates_done'               => $updatesDone,
    'would_update_count'         => $wouldUpdateCount,
    'skipped_no_map'             => $skippedNoMap,
    'skipped_no_owner_map'       => $skippedNoOwnerMap,
    'skipped_no_contact'         => $skippedNoContact,
    'skipped_no_opp'             => $skippedNoOpp,
    'skipped_aligned'            => $skippedAligned,
    'skipped_reopen_not_allowed' => $skippedReopenNotAllowed,
    'deferred_rate_limit'        => $deferredRateLimit,
    'deferred_lookup_http_error' => $deferredLookupHttpError,
    'errors_ghl'                 => $errorsGhl,
    'actions'                    => [
        'reopen_applied'          => $reopenApplied,
        'reopen_would_apply'      => $reopenWouldApply,
        'contact_owner_applied'   => $contactOwnerApplied,
        'contact_owner_would'     => $contactOwnerWouldApply,
        'opp_owner_applied'       => $oppOwnerApplied,
        'opp_owner_would'         => $oppOwnerWouldApply,
        'stage_applied'           => $stageApplied,
        'stage_would'             => $stageWouldApply,
        'cf_applied'              => $cfApplied,
        'cf_would'                => $cfWouldApply,
    ],
    'elapsed_ms'                 => $elapsedMs,
    'changes'                    => $changes,
    'api_calls'                  => [
        'contacts_search' => $GLOBALS['api_calls_contacts_search'],
        'contacts_get'    => $GLOBALS['api_calls_contacts_get'],
        'opps_search'     => $GLOBALS['api_calls_opps_search'],
        'opps_get'        => $GLOBALS['api_calls_opps_get'],
        'put_opp'         => $GLOBALS['api_calls_put_opp'],
        'put_contact'     => $GLOBALS['api_calls_put_contact'],
        'total'           => ghl_requests_used(),
    ],
    'rate_limit_meta'            => $rateLimitMeta,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

exit(0);