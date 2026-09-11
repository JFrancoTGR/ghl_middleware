<?php
declare(strict_types=1);

date_default_timezone_set('America/Tijuana');
header('Content-Type: application/json; charset=utf-8');

const SP_API_KEY                    = 'SP_API_KEY';
const SP_BOT_ID                     = '699c711bc687e92ca105ad00';
const SP_CONTACTS_ENDPOINT          = 'https://api.sendpulse.com/whatsapp/contacts';
const SP_GET_BY_PHONE_ENDPOINT      = 'https://api.sendpulse.com/whatsapp/contacts/getByPhone';
const SP_SEND_TEMPLATE_BY_PHONE_URL = 'https://api.sendpulse.com/whatsapp/contacts/sendTemplateByPhone';
const SP_TEMPLATE_NAME              = 'flujo_lead_nuevo_1';
const SP_TEMPLATE_LANG              = 'es';
const SP_FLOW_INTERESADO            = '69dc1d59bedb6a32670db7c0';
const SP_FLOW_NO_INTERESADO         = '69dc2e03c349e24f1505c2b4';

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/sendpulse_outbound_test.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

function write_log(string $message): void
{
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$ts] $message" . PHP_EOL, FILE_APPEND);
}

function respond(int $statusCode, array $data): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_phone(?string $phone): string
{
    if (!$phone) {
        return '';
    }

    $phone = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($phone) === 10) {
        return '52' . $phone;
    }

    if (strlen($phone) === 12 && str_starts_with($phone, '52')) {
        return $phone;
    }

    if (strlen($phone) === 13 && str_starts_with($phone, '521')) {
        return $phone;
    }

    return $phone;
}

function http_request(
    string $method,
    string $url,
    ?array $payload = null,
    array $extraHeaders = []
): array {
    $ch = curl_init($url);

    $headers = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
    ], $extraHeaders);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);

    if ($payload !== null) {
        curl_setopt(
            $ch,
            CURLOPT_POSTFIELDS,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    return [
        'status'  => $status,
        'body'    => $response,
        'error'   => $error,
        'decoded' => json_decode((string) $response, true),
    ];
}

function sp_request(string $method, string $url, ?array $payload = null): array
{
    return http_request($method, $url, $payload, [
        'Authorization: Bearer ' . SP_API_KEY,
    ]);
}

try {
    $raw = file_get_contents('php://input');
    write_log('RAW INPUT: ' . ($raw ?: '[empty]'));

    if (!$raw) {
        write_log('VALIDATION ERROR: Empty request body');
        respond(400, ['success' => false, 'message' => 'Empty request body']);
    }

    $data = json_decode($raw, true);

    if (!is_array($data)) {
        write_log('VALIDATION ERROR: Invalid JSON');
        respond(400, ['success' => false, 'message' => 'Invalid JSON']);
    }

    $contactId       = trim((string) ($data['contact_id'] ?? ''));
    $firstName       = trim((string) ($data['first_name'] ?? ''));
    $lastName        = trim((string) ($data['last_name'] ?? ''));
    $fullName        = trim((string) ($data['full_name'] ?? ''));
    $email           = trim((string) ($data['email'] ?? ''));
    $phoneRaw        = trim((string) ($data['phone'] ?? ''));
    $pipeline        = trim((string) ($data['pipeline_name'] ?? ''));
    $proyectoInteres = trim((string) ($data['proyecto_relacionado'] ?? ''));

    $phone = normalize_phone($phoneRaw);

    if ($fullName === '') {
        $fullName = trim($firstName . ' ' . $lastName);
    }

    if ($firstName === '') {
        $firstName = explode(' ', $fullName)[0];
    }

    if ($contactId === '') {
        write_log('VALIDATION ERROR: Missing contact_id');
        respond(422, ['success' => false, 'message' => 'Missing contact_id']);
    }

    if ($phone === '') {
        write_log('VALIDATION ERROR: Missing phone');
        respond(422, ['success' => false, 'message' => 'Phone is required']);
    }

    write_log("VALIDATED contact_id={$contactId} phone={$phone} first_name={$firstName} proyecto={$proyectoInteres} bot_id=" . SP_BOT_ID);

    /*
    |--------------------------------------------------------------------------
    | 1) CREATE / UPDATE SUBSCRIBER
    |--------------------------------------------------------------------------
    */
    $contactPayload = [
        'phone'     => $phone,
        'name'      => $fullName,
        'bot_id'    => SP_BOT_ID,
        'tags'      => ['ghl_lead'],
        'variables' => [
            ['name' => 'camp_key',         'value' => 'flujo_lead_nuevo'],
            ['name' => 'ghl_contact_id',   'value' => $contactId],
            ['name' => 'pipeline',         'value' => $pipeline],
            ['name' => 'proyecto_interes', 'value' => $proyectoInteres],
        ],
    ];

    write_log('CONTACT PAYLOAD=' . json_encode($contactPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $spContact = sp_request('POST', SP_CONTACTS_ENDPOINT, $contactPayload);

    write_log(
        'CONTACT RESPONSE status=' . $spContact['status'] .
        ' error=' . $spContact['error'] .
        ' body=' . $spContact['body']
    );

    $subscriberCreated = ($spContact['status'] >= 200 && $spContact['status'] < 300);
    $spSubscriberId    = $spContact['decoded']['id'] ?? null;

    if (!$subscriberCreated) {
        $verifyUrl  = SP_GET_BY_PHONE_ENDPOINT . '?' . http_build_query([
            'phone'  => $phone,
            'bot_id' => SP_BOT_ID,
        ]);
        $verifyResp = sp_request('GET', $verifyUrl);
        write_log('VERIFY RESPONSE status=' . $verifyResp['status'] . ' body=' . $verifyResp['body']);

        $spSubscriberId = $verifyResp['decoded']['data']['id'] ?? null;

        if (!$spSubscriberId) {
            respond(502, [
                'success'    => false,
                'message'    => 'Could not create or find subscriber in SendPulse',
                'contact_id' => $contactId,
                'sp_status'  => $spContact['status'],
                'sp_body'    => $spContact['decoded'] ?? $spContact['body'],
            ]);
        }
    }

    write_log('SUBSCRIBER ID=' . ($spSubscriberId ?? 'null'));

    /*
    |--------------------------------------------------------------------------
    | 2) SEND TEMPLATE WITH QUICK REPLY BUTTONS
    |--------------------------------------------------------------------------
    | Botón 0 → Interesado     → to_chain_id: SP_FLOW_INTERESADO
    | Botón 1 → No interesado  → to_chain_id: SP_FLOW_NO_INTERESADO
    |--------------------------------------------------------------------------
    */
    $templatePayload = [
        'bot_id'   => SP_BOT_ID,
        'phone'    => $phone,
        'template' => [
            'name'       => SP_TEMPLATE_NAME,
            'language'   => [
                'policy' => 'deterministic',
                'code'   => SP_TEMPLATE_LANG,
            ],
            'components' => [
                [
                    'type'       => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $firstName,
                        ],
                    ],
                ],
                [
                    'type'       => 'button',
                    'sub_type'   => 'quick_reply',
                    'index'      => 0,
                    'parameters' => [
                        [
                            'type'    => 'payload',
                            'payload' => [
                                'to_chain_id' => SP_FLOW_INTERESADO,
                            ],
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
                            'payload' => [
                                'to_chain_id' => SP_FLOW_NO_INTERESADO,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    write_log('TEMPLATE PAYLOAD=' . json_encode($templatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $templateResp = sp_request('POST', SP_SEND_TEMPLATE_BY_PHONE_URL, $templatePayload);

    write_log(
        'TEMPLATE RESPONSE status=' . $templateResp['status'] .
        ' error=' . $templateResp['error'] .
        ' body=' . $templateResp['body']
    );

    if (!($templateResp['status'] >= 200 && $templateResp['status'] < 300)) {
        respond(502, [
            'success'            => false,
            'message'            => 'Subscriber registered but template send failed',
            'contact_id'         => $contactId,
            'normalized_phone'   => $phone,
            'first_name'         => $firstName,
            'sp_subscriber_id'   => $spSubscriberId,
            'subscriber_created' => $subscriberCreated,
            'template_status'    => $templateResp['status'],
            'template_error'     => $templateResp['error'],
            'template_body'      => $templateResp['decoded'] ?? $templateResp['body'],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3) ESCRIBIR EN STORAGE DE REACTIVACIÓN
    |--------------------------------------------------------------------------
    | Solo se ejecuta si la plantilla fue entregada exitosamente.
    | El cron job leerá este storage cada 5 minutos para evaluar
    | si el contacto respondió y en caso contrario enviar los toques
    | de reactivación en T+60min y T+7hrs.
    |--------------------------------------------------------------------------
    */
    $storageFile = __DIR__ . '/jobs/storage/reactivacion_leads.json';
    $storageDir  = dirname($storageFile);

    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
    }

    $storage = ['last_run_at' => null, 'contacts' => []];
    if (file_exists($storageFile)) {
        $decoded = json_decode(file_get_contents($storageFile), true);
        if (is_array($decoded)) {
            $storage = $decoded;
        }
    }

    $storage['contacts'][$spSubscriberId] = [
        'ghl_contact_id'  => $contactId,
        'phone'           => $phone,
        'email'           => $email,
        'first_name'      => $firstName,
        'proyecto_interes'=> $proyectoInteres,
        'sp_subscriber_id'=> $spSubscriberId,
        'toque_1_at'      => date('c'),
        'toque_2_at'      => null,
        'toque_3_at'      => null,
        'status'          => 'pendiente',
    ];

    file_put_contents(
        $storageFile,
        json_encode($storage, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    write_log("STORAGE WRITTEN sp_subscriber_id={$spSubscriberId} toque_1_at=" . $storage['contacts'][$spSubscriberId]['toque_1_at']);

    respond(200, [
        'success'            => true,
        'message'            => 'Subscriber registered, template sent and queued for reactivation',
        'contact_id'         => $contactId,
        'normalized_phone'   => $phone,
        'first_name'         => $firstName,
        'proyecto_interes'   => $proyectoInteres,
        'sp_subscriber_id'   => $spSubscriberId,
        'subscriber_created' => $subscriberCreated,
        'template_response'  => $templateResp['decoded'] ?? $templateResp['body'],
    ]);

} catch (Throwable $e) {
    write_log('FATAL ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, [
        'success' => false,
        'message' => 'Internal server error',
        'error'   => $e->getMessage(),
    ]);
}