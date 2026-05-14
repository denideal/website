<?php
// contact.php
// Forward contact form submissions to Postmark.
// Place your Postmark token in an environment variable: POSTMARK_API_TOKEN
// Example form POST action: <form action="/contact.php" method="post">

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Accept JSON or form-encoded body
$contentType = isset($_SERVER['CONTENT_TYPE']) ? trim(explode(';', $_SERVER['CONTENT_TYPE'])[0]) : '';
$input = [];

if ($contentType === 'application/json') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.']);
        exit;
    }
} else {
    $input = $_POST;
}

$firstName = trim((string)($input['firstName'] ?? $input['first_name'] ?? ''));
$lastName  = trim((string)($input['lastName'] ?? $input['last_name'] ?? ''));
$email     = trim((string)($input['email'] ?? ''));
$topic     = trim((string)($input['topic'] ?? '')); 
$message   = trim((string)($input['message'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please provide a valid email address.']);
    exit;
}

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'Message cannot be empty.']);
    exit;
}

function parseEnvFile($path) {
    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
            $name = $matches[1];
            $value = $matches[2];
            if (preg_match('/^(["\'])(.*)\1$/s', $value, $valueMatches)) {
                $value = $valueMatches[2];
            }
            $vars[$name] = $value;
        }
    }
    return $vars;
}

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $env = parseEnvFile($envPath);
    if (isset($env['POSTMARK_API_TOKEN']) && !defined('POSTMARK_API_TOKEN')) {
        define('POSTMARK_API_TOKEN', $env['POSTMARK_API_TOKEN']);
    }
}

$token = defined('POSTMARK_API_TOKEN') ? POSTMARK_API_TOKEN : getenv('POSTMARK_API_TOKEN');
if (!$token) {
    http_response_code(500);
    echo json_encode(['error' => 'Postmark API token is not configured.']);
    exit;
}

$subject = 'Contact form request';
if ($topic) {
    $subject = 'Contact form: ' . $topic;
}

$bodyHtml = '<h2>New contact request</h2>' .
    '<p><strong>Name:</strong> ' . htmlspecialchars($firstName . ' ' . $lastName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' .
    '<p><strong>Email:</strong> ' . htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' .
    '<p><strong>Topic:</strong> ' . htmlspecialchars($topic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' .
    '<p><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';

$postData = [
    'From'    => 'no-reply@denideal.be',
    'To'      => 'info@denideal.be',
    'Subject' => $subject,
    'HtmlBody'=> $bodyHtml,
    'ReplyTo' => $email
];

$ch = curl_init('https://api.postmarkapp.com/email');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-Postmark-Server-Token: ' . $token,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    http_response_code(500);
    echo json_encode(['error' => 'Postmark request failed: ' . $curlErr]);
    exit;
}

$decoded = json_decode($response, true);
if ($status >= 400 || !$decoded || isset($decoded['ErrorCode']) && $decoded['ErrorCode'] != 0) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Postmark API error.',
        'details' => $decoded ?: $response,
    ]);
    exit;
}

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Email sent.']);
