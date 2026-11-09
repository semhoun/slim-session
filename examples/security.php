<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

function exampleJson(ResponseInterface $response, array $value, int $status = 200): ResponseInterface
{
    $response->getBody()->write(json_encode($value, JSON_THROW_ON_ERROR));
    return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
}

function exampleAllowedOrigin(ServerRequestInterface $request, string $mode, array $config): ?string
{
    $origins = $request->getHeader('Origin');
    $allowed = $mode === 'cookie' ? [$config['api']] : [$config['api'], $config['widget']];
    return count($origins) === 1 && in_array($origins[0], $allowed, true) ? $origins[0] : null;
}

function exampleLoginProof(string $origin, string $mode, string $secret): string
{
    $payload = bin2hex(random_bytes(24)) . '.' . (time() + 300);
    return $payload . '.' . hash_hmac('sha256', $mode . "\n" . $origin . "\n" . $payload, $secret);
}

function exampleValidLoginProof(ServerRequestInterface $request, string $mode, array $config): bool
{
    $origin = exampleAllowedOrigin($request, $mode, $config);
    $headers = $request->getHeader('X-Login-CSRF');
    if ($origin === null || count($headers) !== 1 || !preg_match('/^[a-f0-9]{48}\.[0-9]{10}\.[a-f0-9]{64}$/D', $headers[0])) {
        return false;
    }
    $proof = $headers[0];
    [$nonce, $expires, $mac] = explode('.', $proof);
    if ((int) $expires <= time() || (int) $expires > time() + 300
        || !hash_equals(hash_hmac('sha256', $mode . "\n" . $origin . "\n" . $nonce . '.' . $expires, $config['secret']), $mac)) {
        return false;
    }
    if ($mode === 'cookie') {
        $cookie = $request->getCookieParams()['example_login_proof'] ?? null;
        return is_string($cookie) && hash_equals($proof, $cookie);
    }
    return true;
}
