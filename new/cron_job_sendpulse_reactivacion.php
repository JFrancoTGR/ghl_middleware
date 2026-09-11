<?php
// ============================================
// SendPulse Lead Reactivation Cron Job
// - Lee el storage JSON con contactos pendientes
// - Consulta etiquetas en SendPulse para detectar respuesta
// - Si new_lead_interesado o new_lead_no_interesado → elimina del storage
// - Si sin respuesta → envía plantilla de reactivación en T+60min y T+7hrs
// - Si sin respuesta tras T+8hrs → mueve contacto a nurture_leads.json
// - Corre cada 5 minutos via cron
// ============================================

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// ====== CONFIG ======
const SP_API_KEY               = 'SP_API_KEY';
const SP_BOT_ID                = '699c711bc687e92ca105ad00';
const SP_TEMPLATE_LANG         = 'es';
const SP_TEMPLATE_REACTIVAR_60 = 'flujo_lead_reactivar_60min';
const SP_TEMPLATE_REACTIVAR_7H = 'flujo_lead_reactivar_7hrs';
const SP_FLOW_INTERESADO       = '69dc1d59bedb6a32670db7c0';
const SP_FLOW_NO_INTERESADO    = '69dc2e03c349e24f1505c2b4';

// Tiempos en segundos
const TIEMPO_TOQUE_2 = 3600;  // 60 minutos
const TIEMPO_TOQUE_3 = 25200; // 7 horas
const TIEMPO_CIERRE  = 3600; // 8 horas (1hr después del toque 3, si no responde → no interesado)

$stateFile = __DIR__ . '/storage/reactivacion_leads.json';
$lockFile  = __DIR__ . '/storage/reactivacion_leads.lock';
$logFile   = __DIR__ . '/logs/cron_reactivacion_leads.log';
$startedAt = microtime(true);

// ====== DIRECTORIOS ======
foreach ([dirname($stateFile), dirname($logFile)] as $dir) {
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// ====== LOCK ======
$lockHandle = fopen($lockFile, 'c');
if (! $lockHandle || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
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
    if (! file_exists($path)) {
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

function sp_add_tag(string $subscriberId, string $tag): array
{
    $url     = 'https://api.sendpulse.com/whatsapp/contacts/setTag';
    $payload = ['contact_id' => $subscriberId, 'tag' => $tag];
    return http_json('POST', $url, sp_headers(), $payload);
}

function sp_send_template(string $phone, string $templateName, array $bodyParams, array $buttons): array
{
    $components = [
        [
            'type'       => 'body',
            'parameters' => $bodyParams,
        ],
    ];

    foreach ($buttons as $idx => $chainId) {
        $components[] = [
            'type'       => 'button',
            'sub_type'   => 'quick_reply',
            'index'      => $idx,
            'parameters' => [
                [
                    'type'    => 'payload',
                    'payload' => ['to_chain_id' => $chainId],
                ],
            ],
        ];
    }

    $payload = [
        'bot_id'   => SP_BOT_ID,
        'phone'    => $phone,
        'template' => [
            'name'       => $templateName,
            'language'   => ['policy' => 'deterministic', 'code' => SP_TEMPLATE_LANG],
            'components' => $components,
        ],
    ];

    return http_json('POST', 'https://api.sendpulse.com/whatsapp/contacts/sendTemplateByPhone', sp_headers(), $payload);
}

// ====== CONTADORES ======
$processed           = 0;
$skippedInteresado   = 0;
$skippedNoInteresado = 0;
$toque2Sent          = 0;
$toque3Sent          = 0;
$closedNoResponse    = 0;
$errors              = 0;

// ====== ESTADO ======
$state = read_state($stateFile);
$now   = time();

log_line($logFile, date('c') . " START contacts=" . count($state['contacts']));

foreach ($state['contacts'] as $spId => $contact) {
    $processed++;

    $phone           = $contact['phone'] ?? '';
    $firstName       = $contact['first_name'] ?? '';
    $proyectoInteres = $contact['proyecto_interes'] ?? '';
    $toque1At        = $contact['toque_1_at'] ?? null;
    $toque2At        = $contact['toque_2_at'] ?? null;
    $toque3At        = $contact['toque_3_at'] ?? null;
    $toque1Ts        = $toque1At ? strtotime($toque1At) : null;
    $toque2Ts        = $toque2At ? strtotime($toque2At) : null;
    $toque3Ts        = $toque3At ? strtotime($toque3At) : null;

    /*
    |------------------------------------------------------------------
    | 1) Consultar etiquetas en SendPulse
    |------------------------------------------------------------------
    */
    [$http, $body, $err, $decoded] = sp_get_contact($spId);

    if ($err || $http >= 400) {
        $errors++;
        log_line($logFile, date('c') . " ERROR SP_GET_CONTACT sp_id={$spId} http={$http} err={$err}");
        continue;
    }

    $tags = $decoded['data']['tags'] ?? [];

    /*
    |------------------------------------------------------------------
    | 2) Evaluar etiquetas — detectar si ya hubo interacción
    |------------------------------------------------------------------
    */
    if (in_array('new_lead_interesado', $tags)) {
        // Respondió Sí — Johan atiende, salimos del storage
        $skippedInteresado++;
        log_line($logFile, date('c') . " REMOVE new_lead_interesado sp_id={$spId} phone={$phone}");
        unset($state['contacts'][$spId]);
        continue;
    }

    if (in_array('new_lead_no_interesado', $tags)) {
        // Respondió No — ya entró a nurture de días, salimos del storage
        $skippedNoInteresado++;
        log_line($logFile, date('c') . " REMOVE new_lead_no_interesado sp_id={$spId} phone={$phone}");
        unset($state['contacts'][$spId]);
        continue;
    }

    /*
    |------------------------------------------------------------------
    | 3) Sin respuesta — evaluar tiempos y actuar
    |------------------------------------------------------------------
    */

    // T+60min: enviar toque 2
    if ($toque2At === null && $toque1Ts && ($now - $toque1Ts) >= TIEMPO_TOQUE_2) {

        [$th, , $te, $td] = sp_send_template(
            $phone,
            SP_TEMPLATE_REACTIVAR_60,
            [['type' => 'text', 'text' => $firstName]],
            [SP_FLOW_INTERESADO, SP_FLOW_NO_INTERESADO]
        );

        if ($te || $th >= 400) {
            $errors++;
            log_line($logFile, date('c') . " ERROR TOQUE2 sp_id={$spId} phone={$phone} http={$th} err={$te}");
        } else {
            $toque2Sent++;
            $state['contacts'][$spId]['toque_2_at'] = date('c');
            log_line($logFile, date('c') . " OK TOQUE2 sp_id={$spId} phone={$phone} template=" . SP_TEMPLATE_REACTIVAR_60);
        }
        continue;
    }

    // T+7hrs: enviar toque 3
    if ($toque3At === null && $toque2Ts && ($now - $toque2Ts) >= TIEMPO_TOQUE_3) {

        [$th, , $te, $td] = sp_send_template(
            $phone,
            SP_TEMPLATE_REACTIVAR_7H,
            [
                ['type' => 'text', 'text' => $firstName],
                ['type' => 'text', 'text' => $proyectoInteres],
            ],
            [SP_FLOW_INTERESADO, SP_FLOW_NO_INTERESADO]
        );

        if ($te || $th >= 400) {
            $errors++;
            log_line($logFile, date('c') . " ERROR TOQUE3 sp_id={$spId} phone={$phone} http={$th} err={$te}");
        } else {
            $toque3Sent++;
            $state['contacts'][$spId]['toque_3_at'] = date('c');
            log_line($logFile, date('c') . " OK TOQUE3 sp_id={$spId} phone={$phone} template=" . SP_TEMPLATE_REACTIVAR_7H);
        }
        continue;
    }

    // T+cierre: sin respuesta después del toque 3 → mover a nurture de días
    if ($toque3Ts && ($now - $toque3Ts) >= TIEMPO_CIERRE) {

        $nurtureFile  = __DIR__ . '/storage/nurture_leads.json';
        $nurtureState = ['last_run_at' => null, 'contacts' => []];

        if (file_exists($nurtureFile)) {
            $nurtureDecoded = json_decode(file_get_contents($nurtureFile), true);
            if (is_array($nurtureDecoded)) {
                $nurtureState = $nurtureDecoded;
            }
        }

        if (! isset($nurtureState['contacts'][$spId])) {

            $nurtureState['contacts'][$spId] = [
                'ghl_contact_id'     => $contact['ghl_contact_id'] ?? '',
                'phone'              => $phone,
                'email'              => $contact['email'] ?? '',
                'first_name'         => $firstName,
                'proyecto_interes'   => $proyectoInteres,
                'sp_subscriber_id'   => $spId,
                'ingreso_nurture_at' => date('c'),
                'ultimo_toque_at'    => null,
                'ultimo_toque_num'   => 0,
            ];
        }

        file_put_contents(
            $nurtureFile,
            json_encode($nurtureState, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $closedNoResponse++;
        log_line($logFile, date('c') . " MOVE_TO_NURTURE sp_id={$spId} phone={$phone}");
        unset($state['contacts'][$spId]);
        continue;
    }

    // Aún no es tiempo de actuar
    log_line($logFile, date('c') . " WAIT sp_id={$spId} phone={$phone} toque_1_at={$toque1At} toque_2_at={$toque2At} toque_3_at={$toque3At}");
}

// ====== LEER ESTADO =========
$freshState = read_state($stateFile);
foreach ($freshState['contacts'] as $id => $c) {
    if (! isset($state['contacts'][$id])) {
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

log_line($logFile, date('c') . " END elapsed_ms={$elapsedMs} processed={$processed} toque2={$toque2Sent} toque3={$toque3Sent} closed={$closedNoResponse} errors={$errors}");

echo json_encode([
    'ok'                    => true,
    'last_run_at'           => date('c'),
    'processed'             => $processed,
    'removed_interesado'    => $skippedInteresado,
    'removed_no_interesado' => $skippedNoInteresado,
    'toque_2_sent'          => $toque2Sent,
    'toque_3_sent'          => $toque3Sent,
    'closed_no_response'    => $closedNoResponse,
    'errors'                => $errors,
    'elapsed_ms'            => $elapsedMs,
    'pending_contacts'      => count($state['contacts']),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

exit(0);
