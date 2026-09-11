
<?php
// ============================================
// SendPulse Lead Nurture Cron Job
// - Lee el storage JSON nurture_leads.json
// - Consulta last_activity_at en SendPulse para detectar respuesta
// - Si hubo actividad después del último toque → no hacer nada
// - Si no hubo actividad → enviar siguiente plantilla según intervalo
//   Toque 1: día 5  → nurturing_template_plusvalia
//   Toque 2: día 10 → nurturing_template_escalera_inombiliaria
//   Toque 3: día 20 → nurturing_template_experiencia
// - Si se agotaron los 3 toques → descalificar y eliminar del storage
// - Corre cada 5 minutos via cron
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// ====== CONFIG ======
const SP_API_KEY            = 'SP_API_KEY';
const SP_BOT_ID             = '699c711bc687e92ca105ad00';
const SP_TEMPLATE_LANG      = 'es';
const SP_FLOW_INTERESADO    = '69dc1d59bedb6a32670db7c0';
const SP_FLOW_NO_INTERESADO = '69dc2e03c349e24f1505c2b4';

// Plantillas de nurture en orden
const SP_NURTURE_TEMPLATES = [
    1 => 'nurturing_template_plusvalia',
    2 => 'nurturing_template_escalera_inombiliaria',
    3 => 'nurturing_template_experiencia',
];

// Intervalos en segundos desde el toque anterior (o desde ingreso_nurture_at para el toque 1)
const SP_NURTURE_INTERVALS = [
    1 => 300,    // 5 días
    2 => 300,    // 10 días
    3 => 300,   // 20 días
];

const SP_NURTURE_MAX_TOQUES = 3;

$stateFile = __DIR__ . '/storage/nurture_leads.json';
$lockFile  = __DIR__ . '/storage/nurture_leads.lock';
$logFile   = __DIR__ . '/logs/cron_nurture_leads.log';
$startedAt = microtime(true);

// ====== DIRECTORIOS ======
foreach ([dirname($stateFile), dirname($logFile)] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// ====== LOCK ======
$lockHandle = fopen($lockFile, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => false, 'reason' => 'already_running']);
    exit(1);
}

// ====== HELPERS ======
function log_line(string $file, string $line): void
{
    file_put_contents($file, $line . PHP_EOL, FILE_APPEND);
}

function read_state(string $path): array
{
    if (!file_exists($path)) {
        return ['last_run_at' => null, 'contacts' => []];
    }

    $json = json_decode(file_get_contents($path), true);
    return is_array($json) ? $json : ['last_run_at' => null, 'contacts' => []];
}

function write_state(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function http_json(string $method, string $url, array $headers, ?array $payload = null, int $timeout = 25): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [$http, $body, $err, json_decode((string) $body, true)];
}

function sp_headers(): array
{
    return [
        'Authorization: Bearer ' . SP_API_KEY,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}

function sp_get_contact(string $subscriberId): array
{
    $url = 'https://api.sendpulse.com/whatsapp/contacts/get?id=' . urlencode($subscriberId);
    return http_json('GET', $url, sp_headers());
}

function sp_send_nurture_template(string $phone, string $templateName): array
{
    $payload = [
        'bot_id'   => SP_BOT_ID,
        'phone'    => $phone,
        'template' => [
            'name'       => $templateName,
            'language'   => ['policy' => 'deterministic', 'code' => SP_TEMPLATE_LANG],
            'components' => [
                [
                    'type'       => 'button',
                    'sub_type'   => 'quick_reply',
                    'index'      => 0,
                    'parameters' => [
                        [
                            'type'    => 'payload',
                            'payload' => ['to_chain_id' => SP_FLOW_INTERESADO],
                        ],
                    ],
                ],
                [
                    'type'       => 'button',
                    'sub_type'   => 'quick_reply',
                    'index'      => 1,
                    'parameters' => [
                        [
                            'type'    => 'payload',
                            'payload' => ['to_chain_id' => SP_FLOW_NO_INTERESADO],
                        ],
                    ],
                ],
            ],
        ],
    ];

    return http_json('POST', 'https://api.sendpulse.com/whatsapp/contacts/sendTemplateByPhone', sp_headers(), $payload);
}

// ====== CONTADORES ======
$processed      = 0;
$toquesEnviados = 0;
$descalificados = 0;
$skippedActivos = 0;
$errors         = 0;

// ====== ESTADO ======
$state = read_state($stateFile);
$now   = time();

log_line($logFile, date('c') . " START contacts=" . count($state['contacts']));

foreach ($state['contacts'] as $spId => $contact) {
    $processed++;

    $phone          = $contact['phone'] ?? '';
    $ultimoToqueAt  = $contact['ultimo_toque_at'] ?? null;
    $ultimoToqueNum = (int) ($contact['ultimo_toque_num'] ?? 0);
    $ultimoToquets  = $ultimoToqueAt ? strtotime($ultimoToqueAt) : null;
    $ingresoTs      = strtotime($contact['ingreso_nurture_at'] ?? date('c'));

    /*
    |------------------------------------------------------------------
    | 1) Consultar contacto en SendPulse
    |------------------------------------------------------------------
    */
    [$http, , $err, $decoded] = sp_get_contact($spId);

    if ($err || $http >= 400) {
        // Contacto eliminado de SendPulse — limpiar del storage
        log_line($logFile, date('c') . " REMOVE NOT_FOUND sp_id={$spId} phone={$phone} http={$http}");
        unset($state['contacts'][$spId]);
        continue;
    }

    /*
    |------------------------------------------------------------------
    | 2) Verificar si hubo actividad después del último toque
    |    Si respondió → SendPulse maneja con to_chain_id, no actuamos
    |------------------------------------------------------------------
    */
    $lastActivityAt = $decoded['data']['last_activity_at'] ?? null;
    $lastActivityTs = $lastActivityAt ? strtotime($lastActivityAt) : null;

    $baseTs = $ultimoToquets ?? $ingresoTs;

    if ($lastActivityTs && $lastActivityTs > $baseTs) {
        $skippedActivos++;
        log_line($logFile, date('c') . " SKIP ACTIVE sp_id={$spId} phone={$phone} last_activity={$lastActivityAt}");
        continue;
    }

    /*
    |------------------------------------------------------------------
    | 3) Verificar si se agotaron los toques → descalificar
    |------------------------------------------------------------------
    */
    if ($ultimoToqueNum >= SP_NURTURE_MAX_TOQUES) {
        $descalificados++;
        log_line($logFile, date('c') . " DESCALIFICAR sp_id={$spId} phone={$phone} toques={$ultimoToqueNum}");
        unset($state['contacts'][$spId]);
        continue;
    }

    /*
    |------------------------------------------------------------------
    | 4) Evaluar si es tiempo del siguiente toque
    |------------------------------------------------------------------
    */
    $nextToqueNum       = $ultimoToqueNum + 1;
    $intervaloRequerido = SP_NURTURE_INTERVALS[$nextToqueNum] ?? null;

    if (!$intervaloRequerido) {
        $errors++;
        log_line($logFile, date('c') . " ERROR NO_INTERVAL sp_id={$spId} toque_num={$nextToqueNum}");
        continue;
    }

    if (($now - $baseTs) < $intervaloRequerido) {
        $restante = $intervaloRequerido - ($now - $baseTs);
        log_line($logFile, date('c') . " WAIT sp_id={$spId} phone={$phone} next_toque={$nextToqueNum} faltan={$restante}s");
        continue;
    }

    /*
    |------------------------------------------------------------------
    | 5) Enviar plantilla de nurture
    |------------------------------------------------------------------
    */
    $templateName = SP_NURTURE_TEMPLATES[$nextToqueNum] ?? null;

    if (!$templateName) {
        $errors++;
        log_line($logFile, date('c') . " ERROR NO_TEMPLATE sp_id={$spId} toque_num={$nextToqueNum}");
        continue;
    }

    [$th, , $te] = sp_send_nurture_template($phone, $templateName);

    if ($te || $th >= 400) {
        $errors++;
        log_line($logFile, date('c') . " ERROR NURTURE_TOQUE sp_id={$spId} phone={$phone} toque={$nextToqueNum} http={$th} err={$te}");
        continue;
    }

    $toquesEnviados++;
    $state['contacts'][$spId]['ultimo_toque_num'] = $nextToqueNum;
    $state['contacts'][$spId]['ultimo_toque_at']  = date('c');
    log_line($logFile, date('c') . " OK NURTURE_TOQUE sp_id={$spId} phone={$phone} toque={$nextToqueNum} template={$templateName}");
}

// ====== RE-READ para no pisar contactos nuevos ======
$freshState = read_state($stateFile);
foreach ($freshState['contacts'] as $id => $c) {
    if (!isset($state['contacts'][$id])) {
        $state['contacts'][$id] = $c;
    }
}

// ====== GUARDAR ESTADO ======
$state['last_run_at'] = date('c');
write_state($stateFile, $state);

// ====== LIBERAR LOCK ======
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

$elapsedMs = (int) ((microtime(true) - $startedAt) * 1000);

log_line($logFile, date('c') . " END elapsed_ms={$elapsedMs} processed={$processed} toques_enviados={$toquesEnviados} descalificados={$descalificados} skipped_activos={$skippedActivos} errors={$errors}");

echo json_encode([
    'ok'               => true,
    'last_run_at'      => date('c'),
    'processed'        => $processed,
    'toques_enviados'  => $toquesEnviados,
    'descalificados'   => $descalificados,
    'skipped_activos'  => $skippedActivos,
    'errors'           => $errors,
    'elapsed_ms'       => $elapsedMs,
    'pending_contacts' => count($state['contacts']),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

exit(0);