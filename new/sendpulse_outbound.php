<?php
declare (strict_types = 1);

/**
 * SendPulse outbound first-touch handler.
 *
 * Ajuste OOO:
 * - Evalúa horario laboral con zona central de México.
 * - Dentro de horario: envía plantilla normal flujo_lead_nuevo_1 con botones.
 * - Fuera de horario: envía plantilla OOO por proyecto con PDF adjunto, sin botones/to_chain_id.
 */

date_default_timezone_set('America/Mexico_City');
header('Content-Type: application/json; charset=utf-8');

const SP_API_KEY                    = 'SP_API_KEY';
const SP_BOT_ID                     = '699c711bc687e92ca105ad00';
const SP_CONTACTS_ENDPOINT          = 'https://api.sendpulse.com/whatsapp/contacts';
const SP_GET_BY_PHONE_ENDPOINT      = 'https://api.sendpulse.com/whatsapp/contacts/getByPhone';
const SP_SEND_TEMPLATE_BY_PHONE_URL = 'https://api.sendpulse.com/whatsapp/contacts/sendTemplateByPhone';

const SP_TEMPLATE_NAME      = 'flujo_lead_nuevo_1';
const SP_TEMPLATE_LANG      = 'es';
const SP_FLOW_INTERESADO    = '69dc1d59bedb6a32670db7c0';
const SP_FLOW_NO_INTERESADO = '69dc2e03c349e24f1505c2b4';

const BUSINESS_TIMEZONE   = 'America/Mexico_City';
const BUSINESS_HOUR_START = '09:00';
const BUSINESS_HOUR_END   = '19:00';

/**
 * Plantillas OOO aprobadas por proyecto.
 *
 * Nota:
 * - Las plantillas OOO NO tienen botones.
 * - El header document requiere link + filename en runtime.
 * - El body usa parameter_name = proyecto_interes.
 */
const SP_OOO_TEMPLATES_BY_PROJECT = [
    'SENNSE JUAREZ'     => [
        'template'      => 'flujo_lead_ooo_juarez',
        'project_label' => 'SENNSE JUAREZ',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/080426_PDV_SENNSE_JUAREZ.pdf',
        'pdf_filename'  => '080426_PDV_SENNSE_JUAREZ.pdf',
    ],

    'COVA CHILPANCINGO' => [
        'template'      => 'flujo_lead_ooo_chilpancingo',
        'project_label' => 'COVA CHILPANCINGO',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/200226_PDV_COVA_CHILPANCINGO..pdf',
        'pdf_filename'  => '200226_PDV_COVA_CHILPANCINGO.pdf',
    ],

    'SENNSE LIVERPOOL'  => [
        'template'      => 'flujo_lead_ooo_liverpool',
        'project_label' => 'SENNSE LIVERPOOL',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/220226_PDV_LIVERPOOL.pdf',
        'pdf_filename'  => '220226_PDV_LIVERPOOL.pdf',
    ],

    'SENNSE TABACALERA' => [
        'template'      => 'flujo_lead_ooo_tabacalera',
        'project_label' => 'SENNSE TABACALERA',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/220226_PDV_TABACALERA.pdf',
        'pdf_filename'  => '220226_PDV_TABACALERA.pdf',
    ],

    'SENNSE HAMBURGO'   => [
        'template'      => 'flujo_lead_ooo_hamburgo',
        'project_label' => 'SENNSE HAMBURGO',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/240626_PDV_HAMBURGO.pdf',
        'pdf_filename'  => '240626_PDV_HAMBURGO.pdf',
    ],

    'SENNSE VASCONCELOS'   => [
        'template'      => 'flujo_lead_ooo_vasconcelos',
        'project_label' => 'SENNSE VASCONCELOS',
        'pdf_url'       => 'https://fm.sendpul.se/68a4b23958acc5869480fa46b78dc9e58780312/chatbots/flows/ipade2026/260626_PDV_VASCONCELOS.pdf',
        'pdf_filename'  => '260626_PDV_VASCONCELOS.pdf',
    ],
];

/**
 * Aliases defensivos para evitar fallos por acentos, nombres cortos o variaciones del payload.
 */
const SP_PROJECT_ALIASES = [
    'SENNSE JUAREZ'     => 'SENNSE JUAREZ',
    'SENNSE JUÁREZ'     => 'SENNSE JUAREZ',
    'JUAREZ'            => 'SENNSE JUAREZ',
    'JUÁREZ'            => 'SENNSE JUAREZ',

    'COVA CHILPANCINGO' => 'COVA CHILPANCINGO',
    'CHILPANCINGO'      => 'COVA CHILPANCINGO',

    'SENNSE LIVERPOOL'  => 'SENNSE LIVERPOOL',
    'LIVERPOOL'         => 'SENNSE LIVERPOOL',

    'SENNSE TABACALERA' => 'SENNSE TABACALERA',
    'TABACALERA'        => 'SENNSE TABACALERA',

    'SENNSE HAMBURGO' => 'SENNSE HAMBURGO',
    'HAMBURGO' => 'SENNSE HAMBURGO',
    
    'SENNSE VASCONCELOS' => 'SENNSE VASCONCELOS',
    'VASCONCELOS' => 'SENNSE VASCONCELOS',
]; 

/**
 * Para evitar reactivaciones automáticas contradictorias fuera de horario,
 * por defecto solo se agregan al storage los envíos normales.
 */
const QUEUE_REACTIVATION_FOR_OOO = false;

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/sendpulse_outbound.log';

if (! is_dir($logDir)) {
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
    if (! $phone) {
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

function normalize_project_key(string $project): string
{
    $project = trim($project);
    $project = preg_replace('/\s+/', ' ', $project) ?? $project;
    $project = strtoupper($project);

    return $project;
}

function canonical_project_key(string $project): string
{
    $key = normalize_project_key($project);

    return SP_PROJECT_ALIASES[$key] ?? $key;
}

function resolve_ooo_project_config(string $proyectoInteres): ?array
{
    $canonicalKey = canonical_project_key($proyectoInteres);

    return SP_OOO_TEMPLATES_BY_PROJECT[$canonicalKey] ?? null;
}

function get_business_hours_context(?DateTimeImmutable $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(BUSINESS_TIMEZONE));

    $localTime       = $now->format('H:i');
    $isBusinessHours = $localTime >= BUSINESS_HOUR_START && $localTime < BUSINESS_HOUR_END;

    return [
        'is_business_hours' => $isBusinessHours,
        'timezone'          => BUSINESS_TIMEZONE,
        'local_datetime'    => $now->format(DateTimeInterface::ATOM),
        'local_time'        => $localTime,
        'window_start'      => BUSINESS_HOUR_START,
        'window_end'        => BUSINESS_HOUR_END,
    ];
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

function build_normal_template_payload(string $phone, string $firstName): array
{
    return [
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
}

function build_ooo_template_payload(string $phone, array $config): array
{
    return [
        'bot_id'   => SP_BOT_ID,
        'phone'    => $phone,
        'template' => [
            'name'       => $config['template'],
            'language'   => [
                'policy' => 'deterministic',
                'code'   => SP_TEMPLATE_LANG,
            ],
            'components' => [
                [
                    'type'       => 'body',
                    'parameters' => [
                        [
                            'type'           => 'text',
                            'text'           => $config['project_label'],
                            'parameter_name' => 'proyecto_interes',
                        ],
                    ],
                ],
                [
                    'type'       => 'header',
                    'parameters' => [
                        [
                            'type'     => 'document',
                            'document' => [
                                'link'     => $config['pdf_url'],
                                'filename' => $config['pdf_filename'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

function write_reactivation_storage(
    string $spSubscriberId,
    string $contactId,
    string $phone,
    string $email,
    string $firstName,
    string $proyectoInteres,
    string $templateMode,
    array $businessContext
): void {
    $storageFile = __DIR__ . '/jobs/storage/reactivacion_leads.json';
    $storageDir  = dirname($storageFile);

    if (! is_dir($storageDir)) {
        mkdir($storageDir, 0775, true);
    }

    $storage = ['last_run_at' => null, 'contacts' => []];
    if (file_exists($storageFile)) {
        $decoded = json_decode((string) file_get_contents($storageFile), true);
        if (is_array($decoded)) {
            $storage = $decoded;
        }
    }

    $storage['contacts'][$spSubscriberId] = [
        'ghl_contact_id'      => $contactId,
        'phone'               => $phone,
        'email'               => $email,
        'first_name'          => $firstName,
        'proyecto_interes'    => $proyectoInteres,
        'sp_subscriber_id'    => $spSubscriberId,
        'template_mode'       => $templateMode,
        'business_timezone'   => $businessContext['timezone'],
        'business_local_time' => $businessContext['local_datetime'],
        'toque_1_at'          => date('c'),
        'toque_2_at'          => null,
        'toque_3_at'          => null,
        'status'              => 'pendiente',
    ];

    file_put_contents(
        $storageFile,
        json_encode($storage, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    write_log("STORAGE WRITTEN sp_subscriber_id={$spSubscriberId} template_mode={$templateMode} toque_1_at=" . $storage['contacts'][$spSubscriberId]['toque_1_at']);
}

try {
    $raw = file_get_contents('php://input');
    write_log('RAW INPUT: ' . ($raw ?: '[empty]'));

    if (! $raw) {
        write_log('VALIDATION ERROR: Empty request body');
        respond(400, ['success' => false, 'message' => 'Empty request body']);
    }

    $data = json_decode($raw, true);

    if (! is_array($data)) {
        write_log('VALIDATION ERROR: Invalid JSON');
        respond(400, ['success' => false, 'message' => 'Invalid JSON']);
    }

    $contactId = trim((string) ($data['contact_id'] ?? ''));
$firstName = trim((string) ($data['first_name'] ?? ''));
$lastName  = trim((string) ($data['last_name'] ?? ''));
$fullName  = trim((string) ($data['full_name'] ?? ''));
$email     = trim((string) ($data['email'] ?? ''));
$phoneRaw  = trim((string) ($data['phone'] ?? ''));

$pipeline = trim((string) (
    $data['pipeline_name']
    ?? $data['customData']['pipeline_name']
    ?? ''
));

$proyectoInteres = trim((string) (
    $data['customData']['proyecto_relacionado']
    ?? $data['proyecto_relacionado']
    ?? $data['Proyecto Relacionado']
    ?? ''
));

$phone = normalize_phone($phoneRaw);

if ($fullName === '') {
    $fullName = trim($firstName . ' ' . $lastName);
}

if ($firstName === '') {
    $firstName = explode(' ', $fullName)[0] ?? '';
}

if ($contactId === '') {
    write_log('VALIDATION ERROR: Missing contact_id');

    respond(422, [
        'success' => false,
        'message' => 'Missing contact_id',
    ]);
}

if ($phone === '') {
    write_log("VALIDATION ERROR: Missing phone contact_id={$contactId}");

    respond(422, [
        'success'    => false,
        'message'    => 'Phone is required',
        'contact_id' => $contactId,
    ]);
}

if ($proyectoInteres === '') {
    write_log("VALIDATION ERROR: Missing proyecto relacionado contact_id={$contactId}");

    respond(422, [
        'success'    => false,
        'message'    => 'Proyecto relacionado is required',
        'contact_id' => $contactId,
    ]);
}

    $businessContext = get_business_hours_context();
    $isBusinessHours = (bool) $businessContext['is_business_hours'];
    $firstTouchMode  = $isBusinessHours ? 'normal' : 'ooo';

    write_log(
        "VALIDATED contact_id={$contactId} phone={$phone} first_name={$firstName} proyecto={$proyectoInteres} " .
        'bot_id=' . SP_BOT_ID .
        ' business_timezone=' . $businessContext['timezone'] .
        ' business_local_datetime=' . $businessContext['local_datetime'] .
        ' is_business_hours=' . ($isBusinessHours ? '1' : '0')
    );

    /*
    |--------------------------------------------------------------------------
    | 1) CREATE / UPDATE SUBSCRIBER
    |--------------------------------------------------------------------------
    */
    $tags = ['ghl_lead'];
    if (! $isBusinessHours) {
        $tags[] = 'wa_ooo_first_touch';
    }

    $contactPayload = [
        'phone'     => $phone,
        'name'      => $fullName,
        'bot_id'    => SP_BOT_ID,
        'tags'      => $tags,
        'variables' => [
            ['name' => 'camp_key', 'value' => 'flujo_lead_nuevo'],
            ['name' => 'ghl_contact_id', 'value' => $contactId],
            //['name' => 'pipeline',            'value' => $pipeline],
            ['name' => 'proyecto_interes', 'value' => $proyectoInteres],
            ['name' => 'first_touch_mode', 'value' => $firstTouchMode],
            ['name' => 'business_timezone', 'value' => $businessContext['timezone']],
            ['name' => 'business_local_time', 'value' => $businessContext['local_datetime']],
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

    if (! $subscriberCreated) {
        $verifyUrl = SP_GET_BY_PHONE_ENDPOINT . '?' . http_build_query([
            'phone'  => $phone,
            'bot_id' => SP_BOT_ID,
        ]);
        $verifyResp = sp_request('GET', $verifyUrl);
        write_log('VERIFY RESPONSE status=' . $verifyResp['status'] . ' body=' . $verifyResp['body']);

        $spSubscriberId = $verifyResp['decoded']['data']['id'] ?? null;

        if (! $spSubscriberId) {
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
    | 2) SEND TEMPLATE
    |--------------------------------------------------------------------------
    | Horario laboral MX centro:
    |   - Envía template normal flujo_lead_nuevo_1 con quick reply buttons.
    |
    | Fuera de horario MX centro:
    |   - Envía template OOO por proyecto.
    |   - Sin botones y sin to_chain_id.
    |   - Header document con PDF correspondiente al proyecto.
    |--------------------------------------------------------------------------
    */
    $templateMode = 'normal';
    $templateName = SP_TEMPLATE_NAME;
    $oooConfig    = null;

    if ($isBusinessHours) {
        $templatePayload = build_normal_template_payload($phone, $firstName);
        $templateMode    = 'normal';
        $templateName    = SP_TEMPLATE_NAME;
    } else {
        $oooConfig = resolve_ooo_project_config($proyectoInteres);

        if (! $oooConfig) {
            write_log(
                'OOO CONFIG NOT FOUND proyecto=' . $proyectoInteres .
                ' canonical=' . canonical_project_key($proyectoInteres) .
                '; falling back to normal template'
            );

            $templatePayload = build_normal_template_payload($phone, $firstName);
            $templateMode    = 'normal_fallback_no_ooo_config';
            $templateName    = SP_TEMPLATE_NAME;
        } else {
            $templatePayload = build_ooo_template_payload($phone, $oooConfig);
            $templateMode    = 'ooo';
            $templateName    = (string) $oooConfig['template'];
        }
    }

    write_log(
        'TEMPLATE MODE=' . $templateMode .
        ' template_name=' . $templateName .
        ' proyecto=' . $proyectoInteres .
        ' business_local_datetime=' . $businessContext['local_datetime']
    );
    write_log('TEMPLATE PAYLOAD=' . json_encode($templatePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $templateResp = sp_request('POST', SP_SEND_TEMPLATE_BY_PHONE_URL, $templatePayload);

    write_log(
        'TEMPLATE RESPONSE status=' . $templateResp['status'] .
        ' error=' . $templateResp['error'] .
        ' body=' . $templateResp['body']
    );

    if (! ($templateResp['status'] >= 200 && $templateResp['status'] < 300)) {
        respond(502, [
            'success'            => false,
            'message'            => 'Subscriber registered but template send failed',
            'contact_id'         => $contactId,
            'normalized_phone'   => $phone,
            'first_name'         => $firstName,
            'proyecto_interes'   => $proyectoInteres,
            'sp_subscriber_id'   => $spSubscriberId,
            'subscriber_created' => $subscriberCreated,
            'template_mode'      => $templateMode,
            'template_name'      => $templateName,
            'business_context'   => $businessContext,
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
    |
    | Por seguridad operativa, los envíos OOO no se encolan para reactivación
    | automática salvo que QUEUE_REACTIVATION_FOR_OOO = true.
    |--------------------------------------------------------------------------
    */
    $shouldQueueReactivation = $templateMode === 'normal' || QUEUE_REACTIVATION_FOR_OOO;

    if ($shouldQueueReactivation) {
        write_reactivation_storage(
            (string) $spSubscriberId,
            $contactId,
            $phone,
            $email,
            $firstName,
            $proyectoInteres,
            $templateMode,
            $businessContext
        );
    } else {
        write_log("STORAGE SKIPPED sp_subscriber_id={$spSubscriberId} template_mode={$templateMode} reason=ooo_not_queued");
    }

    respond(200, [
        'success'             => true,
        'message'             => $shouldQueueReactivation
            ? 'Subscriber registered, template sent and queued for reactivation'
            : 'Subscriber registered and OOO template sent without reactivation queue',
        'contact_id'          => $contactId,
        'normalized_phone'    => $phone,
        'first_name'          => $firstName,
        'proyecto_interes'    => $proyectoInteres,
        'sp_subscriber_id'    => $spSubscriberId,
        'subscriber_created'  => $subscriberCreated,
        'template_mode'       => $templateMode,
        'template_name'       => $templateName,
        'queued_reactivation' => $shouldQueueReactivation,
        'business_context'    => $businessContext,
        'template_response'   => $templateResp['decoded'] ?? $templateResp['body'],
    ]);

} catch (Throwable $e) {
    write_log('FATAL ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    respond(500, [
        'success' => false,
        'message' => 'Internal server error',
        'error'   => $e->getMessage(),
    ]);
}
