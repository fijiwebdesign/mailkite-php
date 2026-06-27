<?php
// Server-side login + register.
//
//   A) Your OWN account: call signup (register) or login with email + password, keep the token.
//   B) YOUR USERS' accounts (multi-tenant): the OAuth 2.1 + PKCE flow — send the user to MailKite's
//      hosted page where they LOG IN OR REGISTER, then exchange the returned `code` for a token that
//      *is* that user. Register-or-login is handled on the hosted page; a new user just signs up
//      there and lands back logged in.
//
// Run:  MAILKITE_BASE_URL=https://api.mailkite.dev php -S localhost:3000 05-server-login.php
//       then open http://localhost:3000/login
// Deps: composer require mailkite/mailkite

require __DIR__ . '/../vendor/autoload.php';

$issuer = getenv('MAILKITE_BASE_URL') ?: 'https://api.mailkite.dev';
$redirectUri = 'http://localhost:3000/callback';

function b64url(string $b): string
{
    return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

// Tiny JSON POST helper (no curl needed — file_get_contents + a stream context).
function postJson(string $url, array $body): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode($body),
        'ignore_errors' => true,  // still read the body on 4xx (e.g. 409)
    ]]);
    $raw = file_get_contents($url, false, $ctx);
    return [$http_response_header ?? [], $raw === false ? null : json_decode($raw, true)];
}

// Form-encoded POST (the OAuth token endpoint wants application/x-www-form-urlencoded).
function postForm(string $url, array $body): ?array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($body),
        'ignore_errors' => true,
    ]]);
    $raw = file_get_contents($url, false, $ctx);
    return $raw === false ? null : json_decode($raw, true);
}

// Parse the numeric HTTP status out of $http_response_header.
function statusOf(array $headers): int
{
    return isset($headers[0]) && preg_match('#\s(\d{3})\s#', $headers[0], $m) ? (int) $m[1] : 0;
}

// ── A) Server acting as your OWN single account (no redirect) ───────────────────────────────────
function ownAccount(string $issuer): void
{
    [$headers, $data] = postJson("$issuer/api/auth/signup", [
        'email' => 'you@example.com', 'password' => getenv('MK_PASSWORD'),
    ]);
    if (statusOf($headers) === 409) {  // already registered → log in instead
        [$headers, $data] = postJson("$issuer/api/auth/login", [
            'email' => 'you@example.com', 'password' => getenv('MK_PASSWORD'),
        ]);
    }
    $mk = new \MailKite\Client($data['token']);  // the session token works like an API key
    echo 'logged in as own account; domains: ' . json_encode($mk->listDomains()) . "\n";
}

// ── B) OAuth login/register for YOUR USERS ───────────────────────────────────────────────────────
// Demo store: state → {verifier, client_id}, kept in a temp file so it survives the redirect hop.
// Use a real session store in prod.
function sessionStore(string $state, ?array $value = null): ?array
{
    $path = sys_get_temp_dir() . "/mk_oauth_$state.json";
    if ($value !== null) {
        file_put_contents($path, json_encode($value));
        return $value;
    }
    if (!is_file($path)) {
        return null;
    }
    $v = json_decode(file_get_contents($path), true);
    @unlink($path);
    return $v;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/login') {
    [, $reg] = postJson("$issuer/oauth/register", [
        'client_name' => 'My App', 'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
    ]);
    $verifier = b64url(random_bytes(32));
    $challenge = b64url(hash('sha256', $verifier, true));  // PKCE S256: SHA-256(verifier), base64url
    $state = b64url(random_bytes(16));
    sessionStore($state, ['verifier' => $verifier, 'client_id' => $reg['client_id']]);
    $params = http_build_query([
        'response_type' => 'code', 'client_id' => $reg['client_id'], 'redirect_uri' => $redirectUri,
        'scope' => 'mcp', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]);
    header("Location: $issuer/oauth/authorize?$params");
    return;
}

if ($path === '/callback') {
    $sess = sessionStore($_GET['state'] ?? '');
    if (!$sess) {
        http_response_code(400);
        echo 'unknown state';
        return;
    }
    $tok = postForm("$issuer/oauth/token", [
        'grant_type' => 'authorization_code', 'code' => $_GET['code'] ?? '', 'redirect_uri' => $redirectUri,
        'client_id' => $sess['client_id'], 'code_verifier' => $sess['verifier'],
    ]);
    $mk = new \MailKite\Client($tok['access_token']);  // now act as that user (store refresh_token to renew later)
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Logged in as the MailKite user.', 'domains' => $mk->listDomains()]);
    return;
}

// CLI entrypoint: run the own-account flow directly (php 05-server-login.php).
if (PHP_SAPI === 'cli') {
    ownAccount($issuer);
} else {
    echo "Open /login to start the OAuth flow.\n";
}
