<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth_middleware
{
    private static ?array $payload = null;

    public function handle($next)
    {
        self::$payload = null;

        $lava = lava_instance();
        $lava->call->library('api');
        $payload = $lava->api->require_jwt();

        if (($payload['type'] ?? '') === 'refresh') {
            $lava->api->respond_error('Unauthorized', 401);
        }

        self::$payload = $payload;
        return $next();
    }

    public static function payload(): ?array
    {
        return self::$payload;
    }
}
