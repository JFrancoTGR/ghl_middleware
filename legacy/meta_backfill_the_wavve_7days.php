<?php
/**
 * Meta CAPI Backfill (last 7 days) - The WAVVE (multi-milestone, WON by status)
 *
 * Rules:
 * 1) Only Meta leads:
 *    - contact.source == "Facebook" OR attributionSource.medium == "facebook"
 * 2) Window:
 *    - For stage-based milestones: use lastStageChangeAt (fallback updatedAt)
 *    - For WON: use lastStatusChangeAt (fallback updatedAt)
 *    - Must be within last 7 days (Meta constraint)
 * 3) Milestones:
 *    - Seguimiento -> stageId (event QualifiedLead)
 *    - Cita        -> stageId (event Schedule, appointment_type=cita)
 *    - Visita      -> stageId (event Schedule, appointment_type=visita)
 *    - Vendido     -> status == "won" (event Purchase)
 * 4) Idempotency:
 *    - event_id = meta:4:<oppId>:<milestone>
 *    - registry JSON prevents re-sending
 *
 * Run:
 *   php meta_backfill_the_wavve_7days_milestones.php
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only\n"); }

// -------------------- CONFIG (WAVVE) --------------------
$GHL_TOKEN  = getenv('GHL_TOKEN_WAVVE') ?: 'GHL_TOKEN';
$META_TOKEN = getenv('META_TOKEN_WAVVE') ?: 'META_TOKEN';

$LOCATION_ID = 'PKIWfuiKgY90ZpNOwqCO';
$DATASET_ID  = '2733595613445534';

$PIPELINE_ID = 'iP10rv3zvKimvSVz6xJv';

// ✅ Pon tus stage IDs reales
$STAGE_ID_SEGUIMIENTO = '25f85a37-4b6f-4460-a2cc-a70abd62c0b4';
$STAGE_ID_CITA        = '79d89924-db28-430c-824c-6f32cb343b5f';
$STAGE_ID_VISITA      = '10cd5e99-44dc-4fe6-8fb6-976d8a1420f7';

// Valores (jerarquía)
$CURRENCY_DEFAULT = 'MXN';
$VALUE_SEGUIMIENTO = 1;
$VALUE_CITA        = 3;
$VALUE_VISITA      = 8;
$VALUE_VENDIDO     = 100;

$DAYS_BACK = 7;

// -------------------- STORAGE --------------------
$REGISTRY_DIR = __DIR__ . '/storage';
if (!is_dir($REGISTRY_DIR)) mkdir($REGISTRY_DIR, 0775, true);
$REGISTRY_PATH = "{$REGISTRY_DIR}/meta_backfill_registry_wavve_milestones.json";

// -------------------- VALIDATE CONFIG --------------------
foreach ([
  'GHL_TOKEN' => $GHL_TOKEN,
  'META_TOKEN'=> $META_TOKEN,
  'PIPELINE_ID'=> $PIPELINE_ID,
  'STAGE_ID_SEGUIMIENTO'=> $STAGE_ID_SEGUIMIENTO,
  'STAGE_ID_CITA'=> $STAGE_ID_CITA,
  'STAGE_ID_VISITA'=> $STAGE_ID_VISITA,
] as $k=>$v) {
  if (empty($v) || str_contains($v, 'REPLACE_ME')) exit("Missing/placeholder config: {$k}\n");
}

// -------------------- HELPERS --------------------
function http_json(string $method, string $url, array $headers, ?array $body, int $timeout = 30): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_HTTPHEADER     => $headers,
  ]);
  if ($body !== null) {
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  }
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $json = json_decode((string)$raw, true);
  return ['ok'=>($code>=200 && $code<300), 'code'=>$code, 'raw'=>$raw, 'json'=>$json, 'err'=>$err];
}

function ghl_headers(string $token): array {
  return [
    "Accept: application/json",
    "Content-Type: application/json",
    "Authorization: Bearer {$token}",
    "Version: 2021-07-28",
  ];
}

function sha256_norm(?string $s): ?string {
  if (!$s) return null;
  $v = trim(mb_strtolower($s));
  return $v === '' ? null : hash('sha256', $v);
}

function normalize_phone(?string $p): ?string {
  if (!$p) return null;
  $v = preg_replace('/\s+/', '', trim($p));
  if ($v === '') return null;
  $v = preg_replace('/(?!^\+)[^\d]/', '', $v); // keep + and digits
  return $v;
}

function is_meta_lead(array $contact): bool {
  if (!empty($contact['source']) && mb_strtolower($contact['source']) === 'facebook') return true;
  $attr = $contact['attributionSource'] ?? [];
  if (!empty($attr['medium']) && mb_strtolower($attr['medium']) === 'facebook') return true;
  return false;
}

function load_registry(string $path): array {
  if (!file_exists($path)) return [];
  $raw = file_get_contents($path);
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

function save_registry(string $path, array $reg): void {
  file_put_contents($path, json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function ghl_search_opps(string $token, string $locationId, array $payload): array {
  // Tenant requires locationId in body too
  $payload['locationId'] = $locationId;
  $url = "https://services.leadconnectorhq.com/opportunities/search?locationId={$locationId}";
  return http_json('POST', $url, ghl_headers($token), $payload);
}

function ghl_get_contact(string $token, string $contactId): array {
  $url = "https://services.leadconnectorhq.com/contacts/{$contactId}";
  return http_json('GET', $url, ghl_headers($token), null);
}

function meta_send(string $token, string $datasetId, array $event): array {
  $url = "https://graph.facebook.com/v18.0/{$datasetId}/events";
  $headers = ["Content-Type: application/json", "Authorization: Bearer {$token}"];
  return http_json('POST', $url, $headers, ['data'=>[$event]]);
}

function parse_ts(?string $iso): ?int {
  if (!$iso) return null;
  $t = strtotime($iso);
  return $t ? $t : null;
}

// -------------------- MILESTONE RESOLUTION --------------------
// Priority order: WON first (strongest), else stage-based
function resolve_milestone(array $opp, array $cfg): ?array {
  $status = $opp['status'] ?? null;

  // 1) WON -> vendido
  if ($status === 'won') {
    $ts = parse_ts($opp['lastStatusChangeAt'] ?? null) ?? parse_ts($opp['updatedAt'] ?? null);
    return [
      'milestone'        => 'vendido',
      'event_name'       => 'Purchase',
      'appointment_type' => null,
      'value'            => $cfg['VALUE_VENDIDO'],
      'currency'         => $cfg['CURRENCY_DEFAULT'],
      'event_ts'         => $ts,
      'source_field'     => 'lastStatusChangeAt',
    ];
  }

  // 2) Stage-based
  $stageId = $opp['pipelineStageId'] ?? null;
  if (!$stageId) return null;

  $ts = parse_ts($opp['lastStageChangeAt'] ?? null) ?? parse_ts($opp['updatedAt'] ?? null);

  if ($stageId === $cfg['STAGE_ID_SEGUIMIENTO']) {
    return [
      'milestone'        => 'seguimiento',
      'event_name'       => 'QualifiedLead', // alternativa: Contact
      'appointment_type' => null,
      'value'            => $cfg['VALUE_SEGUIMIENTO'],
      'currency'         => $cfg['CURRENCY_DEFAULT'],
      'event_ts'         => $ts,
      'source_field'     => 'lastStageChangeAt',
    ];
  }

  if ($stageId === $cfg['STAGE_ID_CITA']) {
    return [
      'milestone'        => 'cita',
      'event_name'       => 'Schedule',
      'appointment_type' => 'cita',
      'value'            => $cfg['VALUE_CITA'],
      'currency'         => $cfg['CURRENCY_DEFAULT'],
      'event_ts'         => $ts,
      'source_field'     => 'lastStageChangeAt',
    ];
  }

  if ($stageId === $cfg['STAGE_ID_VISITA']) {
    return [
      'milestone'        => 'visita',
      'event_name'       => 'Schedule',
      'appointment_type' => 'visita',
      'value'            => $cfg['VALUE_VISITA'],
      'currency'         => $cfg['CURRENCY_DEFAULT'],
      'event_ts'         => $ts,
      'source_field'     => 'lastStageChangeAt',
    ];
  }

  return null;
}

// -------------------- RUN --------------------
$registry = load_registry($REGISTRY_PATH);
$sinceTs  = time() - ($DAYS_BACK * 86400);

echo "== WAVVE backfill milestones last {$DAYS_BACK} days (WON by status) ==\n";
echo "Location: {$LOCATION_ID}\nPipeline: {$PIPELINE_ID}\nDataset: {$DATASET_ID}\n";
echo "Seguimiento stage: {$STAGE_ID_SEGUIMIENTO}\nCita stage: {$STAGE_ID_CITA}\nVisita stage: {$STAGE_ID_VISITA}\n";
echo "Registry: {$REGISTRY_PATH}\n\n";

$cfg = [
  'STAGE_ID_SEGUIMIENTO' => $STAGE_ID_SEGUIMIENTO,
  'STAGE_ID_CITA'        => $STAGE_ID_CITA,
  'STAGE_ID_VISITA'      => $STAGE_ID_VISITA,
  'CURRENCY_DEFAULT'     => $CURRENCY_DEFAULT,
  'VALUE_SEGUIMIENTO'    => $VALUE_SEGUIMIENTO,
  'VALUE_CITA'           => $VALUE_CITA,
  'VALUE_VISITA'         => $VALUE_VISITA,
  'VALUE_VENDIDO'        => $VALUE_VENDIDO,
];

$sent=0; $skip=0; $fail=0; $scanned=0; $eligible=0; $metaEligible=0;

$page = 1;
while (true) {
  $payload = ['limit'=>100, 'page'=>$page]; // tenant-safe payload
  $res = ghl_search_opps($GHL_TOKEN, $LOCATION_ID, $payload);

  if (!$res['ok']) {
    echo "ERROR opp search HTTP {$res['code']}: {$res['raw']}\n";
    $fail++;
    break;
  }

  $items = $res['json']['opportunities'] ?? $res['json']['data'] ?? [];
  if (!is_array($items) || count($items) === 0) break;

  foreach ($items as $opp) {
    $scanned++;

    $oppId     = $opp['id'] ?? null;
    $contactId = $opp['contactId'] ?? ($opp['contact']['id'] ?? null);
    if (!$oppId || !$contactId) { $skip++; continue; }

    // pipeline filter
    if (($opp['pipelineId'] ?? null) !== $PIPELINE_ID) { $skip++; continue; }

    // resolve milestone (WON first)
    $mcfg = resolve_milestone($opp, $cfg);
    if (!$mcfg) { $skip++; continue; }

    // window filter (7 days)
    $eventTsCandidate = $mcfg['event_ts'] ?? null;
    if ($eventTsCandidate && $eventTsCandidate < $sinceTs) { $skip++; continue; }

    $eligible++;

    // idempotent event id per milestone
    $milestone = $mcfg['milestone'];
    $eventId = "meta:4:{$oppId}:{$milestone}";
    if (isset($registry[$eventId])) { $skip++; continue; }

    // load contact for meta-only + identifiers
    $c = ghl_get_contact($GHL_TOKEN, $contactId);
    if (!$c['ok']) {
      $registry[$eventId] = ['status'=>'fail_contact','ts'=>time(),'oppId'=>$oppId,'contactId'=>$contactId,'error'=>"HTTP {$c['code']}"];
      $fail++;
      continue;
    }

    $contact = $c['json']['contact'] ?? [];
    if (!is_meta_lead($contact)) {
      $registry[$eventId] = ['status'=>'skip_not_meta','ts'=>time(),'oppId'=>$oppId,'contactId'=>$contactId];
      $skip++;
      continue;
    }

    $metaEligible++;

    $emailHash = sha256_norm($contact['email'] ?? null);
    $phoneNorm = normalize_phone($contact['phone'] ?? null);
    $phoneHash = $phoneNorm ? hash('sha256', $phoneNorm) : null;

    if (!$emailHash && !$phoneHash) {
      $registry[$eventId] = ['status'=>'skip_no_user_data','ts'=>time(),'oppId'=>$oppId,'contactId'=>$contactId];
      $skip++;
      continue;
    }

    // event_time must be within last 7 days
    $eventTime = $eventTsCandidate ?: time();
    if ($eventTime < $sinceTs) $eventTime = $sinceTs + 60;

    // Build custom_data
    $custom = [
      'project_id'           => 4,
      'project_name'         => 'The WAVVE',
      'milestone'            => $milestone,
      'ghl_location_id'      => $LOCATION_ID,
      'ghl_contact_id'       => $contactId,
      'ghl_opportunity_id'   => $oppId,
      'ghl_pipeline_id'      => $PIPELINE_ID,
      'ghl_pipeline_stage_id'=> ($opp['pipelineStageId'] ?? null),
      'ghl_status'           => ($opp['status'] ?? null),
      'value'                => $mcfg['value'],
      'currency'             => $mcfg['currency'],
      'event_time_source'    => $mcfg['source_field'],
    ];
    if (!empty($mcfg['appointment_type'])) $custom['appointment_type'] = $mcfg['appointment_type'];

    $event = [
      'event_name'    => $mcfg['event_name'],
      'event_time'    => $eventTime,
      'action_source' => 'system_generated',
      'event_id'      => $eventId,
      'user_data'     => array_filter([
        'em' => $emailHash ? [$emailHash] : null,
        'ph' => $phoneHash ? [$phoneHash] : null,
        'external_id' => [$contactId],
      ]),
      'custom_data'   => $custom,
    ];

    $mr = meta_send($META_TOKEN, $DATASET_ID, $event);
    if ($mr['ok']) {
      $registry[$eventId] = [
        'status'    => 'sent',
        'ts'        => time(),
        'oppId'     => $oppId,
        'contactId' => $contactId,
        'milestone' => $milestone,
        'meta'      => $mr['json'],
      ];
      $sent++;
    } else {
      $registry[$eventId] = [
        'status'    => 'fail_meta',
        'ts'        => time(),
        'oppId'     => $oppId,
        'contactId' => $contactId,
        'milestone' => $milestone,
        'error'     => $mr['raw'],
      ];
      $fail++;
    }
  }

  save_registry($REGISTRY_PATH, $registry);

  $page++;
  if ($page > 50) break; // safety cap
}

save_registry($REGISTRY_PATH, $registry);

echo "\n== DONE WAVVE milestones (WON by status) ==\n";
echo "Scanned: {$scanned}\nEligible (pipeline+milestone+window): {$eligible}\nEligible Meta-only: {$metaEligible}\n";
echo "Sent: {$sent}\nSkipped: {$skip}\nFailed: {$fail}\nRegistry: {$REGISTRY_PATH}\n";
