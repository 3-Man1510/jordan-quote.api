<?php
declare(strict_types=1);

/**
 * POST /api/quote.php
 * Accepts a JSON body { name, email, details, phone?, service? }, validates it,
 * and emails it to you via the Resend REST API (called with cURL).
 *
 * Required env var:  EMAIL_API_KEY   (your Resend API key)
 * Optional env vars: TO_EMAIL, FROM_EMAIL, ALLOWED_ORIGIN
 */

// ---------- Configuration (set these in Vercel → Settings → Environment Variables) ----------
$RESEND_API_KEY = getenv('EMAIL_API_KEY') ?: '';
$TO_EMAIL       = getenv('TO_EMAIL')       ?: 'you@example.com';                         // where requests are delivered
$FROM_EMAIL     = getenv('FROM_EMAIL')     ?: 'Jordan Painting <onboarding@resend.dev>'; // must be a Resend-verified sender for real recipients
$ALLOWED_ORIGIN = getenv('ALLOWED_ORIGIN') ?: '*';                                       // e.g. https://jordan-painting.vercel.app

// ---------- CORS ----------
header('Access-Control-Allow-Origin: ' . $ALLOWED_ORIGIN);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

// Preflight request — respond and stop.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only POST is allowed for the actual send.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---------- Parse & validate the JSON body ----------
$raw  = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

/** Trim, strip any HTML tags, and cap the length of a value. */
function clean_field($value, int $max = 2000): string {
    $value = is_scalar($value) ? (string) $value : '';
    $value = trim(strip_tags($value));
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

$name    = clean_field($data['name']    ?? '', 120);
$email   = trim((string) ($data['email'] ?? ''));
$details = clean_field($data['details'] ?? '', 4000);
$phone   = clean_field($data['phone']   ?? '', 40);   // optional
$service = clean_field($data['service'] ?? '', 120);  // optional

$invalid = [];
if ($name === '')                                    { $invalid[] = 'name'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))      { $invalid[] = 'email'; }
if ($details === '')                                 { $invalid[] = 'details'; }

if (!empty($invalid)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Missing or invalid fields', 'fields' => $invalid]);
    exit;
}

if ($RESEND_API_KEY === '') {
    // Misconfiguration — don't reveal details to the client.
    error_log('quote.php: EMAIL_API_KEY is not set');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server not configured']);
    exit;
}

// ---------- Build the email (escape every user value for HTML) ----------
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$html = '<h2 style="font-family:sans-serif">New quote request</h2>'
      . '<p style="font-family:sans-serif"><strong>Name:</strong> ' . $e($name) . '</p>'
      . '<p style="font-family:sans-serif"><strong>Email:</strong> ' . $e($email) . '</p>'
      . ($phone   !== '' ? '<p style="font-family:sans-serif"><strong>Phone:</strong> ' . $e($phone) . '</p>' : '')
      . ($service !== '' ? '<p style="font-family:sans-serif"><strong>Service:</strong> ' . $e($service) . '</p>' : '')
      . '<p style="font-family:sans-serif"><strong>Details:</strong><br>' . nl2br($e($details)) . '</p>';

$payload = [
    'from'     => $FROM_EMAIL,
    'to'       => [$TO_EMAIL],
    'reply_to' => $email,                         // reply goes straight to the customer
    'subject'  => 'New quote request — ' . $name,
    'html'     => $html,
];

// ---------- Send via the Resend REST API using cURL ----------
$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $RESEND_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 15,
]);

$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('quote.php: cURL error: ' . $curlErr);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Email service unreachable']);
    exit;
}

if ($status >= 200 && $status < 300) {
    http_response_code(200);
    echo json_encode(['success' => true]);
} else {
    // Log the provider's response server-side; keep the client message generic.
    error_log('quote.php: Resend returned ' . $status . ': ' . $response);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Could not send email']);
}
