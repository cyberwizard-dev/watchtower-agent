<?php

namespace Cyberwizard\WatchtowerAgent\Actions;

/**
 * signature = hex hmac_sha256("{id}|{type}|{json payload}|{issued_at}|{expires_at}", secret)
 */
class ActionSignature
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sign(string $id, string $type, array $payload, int $issuedAt, int $expiresAt, string $secret): string
    {
        return hash_hmac('sha256', self::message($id, $type, $payload, $issuedAt, $expiresAt), $secret);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function message(string $id, string $type, array $payload, int $issuedAt, int $expiresAt): string
    {
        return implode('|', [$id, $type, (string) json_encode($payload, JSON_UNESCAPED_SLASHES), $issuedAt, $expiresAt]);
    }
}
