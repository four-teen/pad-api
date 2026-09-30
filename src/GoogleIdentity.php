<?php

// HTTP errors that can also be asserted by the authentication smoke tests.
class ApiException extends RuntimeException
{
}

class AuthenticationException extends ApiException
{
}

final class GoogleIdentity
{
    private $clientId;
    private $certificateLoader;

    public function __construct($clientId, callable $certificateLoader)
    {
        $this->clientId = $clientId;
        $this->certificateLoader = $certificateLoader;
    }

    public function verify($credential)
    {
        if (!$this->clientId || strpos($this->clientId, 'your-google') === 0) {
            throw new AuthenticationException('Google sign-in has not been configured yet.', 503);
        }
        if (!is_string($credential) || strlen($credential) > 16384) {
            $this->invalid();
        }
        $parts = explode('.', $credential);
        if (count($parts) !== 3) {
            $this->invalid();
        }
        $header = json_decode($this->decode($parts[0]), true);
        $claims = json_decode($this->decode($parts[1]), true);
        $signature = $this->decode($parts[2]);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256'
            || empty($header['kid']) || !is_string($header['kid'])) {
            $this->invalid();
        }

        $now = time();
        if (!isset($claims['aud']) || !is_string($claims['aud']) || !hash_equals($this->clientId, $claims['aud'])
            || !in_array($claims['iss'] ?? '', array('accounts.google.com', 'https://accounts.google.com'), true)
            || !isset($claims['exp'], $claims['iat']) || !is_int($claims['exp']) || !is_int($claims['iat'])
            || $claims['exp'] <= $now || $claims['iat'] > $now + 60
            || empty($claims['sub']) || !is_string($claims['sub']) || strlen($claims['sub']) > 191
            || empty($claims['email']) || !is_string($claims['email']) || strlen($claims['email']) > 191
            || !filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            || ($claims['email_verified'] ?? false) !== true) {
            $this->invalid();
        }

        $certificates = call_user_func($this->certificateLoader);
        // The key location is fixed server-side; never use a URL supplied by a JWT.
        if (!isset($certificates[$header['kid']])
            || openssl_verify($parts[0] . '.' . $parts[1], $signature, $certificates[$header['kid']], OPENSSL_ALGO_SHA256) !== 1) {
            $this->invalid();
        }
        return $claims;
    }

    private function decode($part)
    {
        if ($part === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
            $this->invalid();
        }
        $decoded = base64_decode(strtr($part, '-_', '+/'), true);
        if ($decoded === false) {
            $this->invalid();
        }
        return $decoded;
    }

    private function invalid()
    {
        throw new AuthenticationException('Google sign-in could not be validated. Please try again.', 401);
    }
}

function google_signing_certificates()
{
    $cachePath = PAD_ROOT . '/storage/google-certificates.json';
    $cached = is_file($cachePath) ? json_decode((string) @file_get_contents($cachePath), true) : null;
    if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() && !empty($cached['certificates'])) {
        return $cached['certificates'];
    }

    $url = 'https://www.googleapis.com/oauth2/v1/certs';
    $maxAge = 0;
    $response = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$maxAge) {
                if (stripos($line, 'Cache-Control:') === 0 && preg_match('/max-age=(\d+)/i', $line, $matches)) {
                    $maxAge = (int) $matches[1];
                }
                return strlen($line);
            }
        ));
        $response = curl_exec($curl);
        if ((int) curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
            $response = false;
        }
        curl_close($curl);
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create(array(
            'http' => array('timeout' => 10, 'follow_location' => 0),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true)
        ));
        $response = @file_get_contents($url, false, $context);
        foreach ($http_response_header ?? array() as $line) {
            if (stripos($line, 'Cache-Control:') === 0 && preg_match('/max-age=(\d+)/i', $line, $matches)) {
                $maxAge = (int) $matches[1];
            }
        }
    }

    $certificates = $response === false ? null : json_decode($response, true);
    if (!is_array($certificates) || !$certificates) {
        throw new AuthenticationException('Google sign-in is temporarily unavailable. Please try again.', 503);
    }
    foreach ($certificates as $certificate) {
        if (!is_string($certificate) || !openssl_x509_read($certificate)) {
            throw new AuthenticationException('Google sign-in is temporarily unavailable. Please try again.', 503);
        }
    }
    if ($maxAge > 0) {
        // An unwritable cache does not prevent sign-in; TLS verification is always required.
        @file_put_contents($cachePath, json_encode(array(
            'expires_at' => time() + min($maxAge, 86400), 'certificates' => $certificates
        )), LOCK_EX);
    }
    return $certificates;
}
