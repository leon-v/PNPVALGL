<?php

// FixtureHelpers.php — shared auth, token, and error-response helpers
// for test-fixture scripts.

class FixtureHelpers
{
    /**
     * Exit with a JSON error response.
     *
     * @param int    $status  HTTP status code.
     * @param string $message Error message.
     */
    public static function sendJsonError(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }

    /**
     * Require a valid auth key, exiting 403 if missing or invalid.
     */
    public static function requireAuth(): ?string
    {
        $authKey = (string) getenv('LOCAL_AUTH_KEY');
        $token = (string) $_GET['token'] ?? null;

        if ($token === null) {
            self::sendJsonError(403, 'Missing ?token= parameter');
        }

        if (!$authKey){
            self::sendJsonError(500, 'Server misconfiguration: LOCAL_AUTH_KEY not set');
        }

        if ($authKey === '' || !hash_equals($authKey, $token)) {
            self::sendJsonError(403, 'Missing or invalid ?token= parameter');
        }

        return $token;
    }
}
