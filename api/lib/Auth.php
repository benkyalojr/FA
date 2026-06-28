<?php
/**
 * Bearer-token auth. Credentials are validated against FrontAccounting's own
 * users table (0_users); tokens are stored in the custom avogs_api_tokens table
 * (FA has no token concept). token.user_id references 0_users.id.
 */
class Auth
{
    public static function bearer(Request $req)
    {
        $h = $req->header('Authorization');
        if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Resolve the authenticated FA user for this request, or 401. */
    public static function requireUser(Request $req)
    {
        $token = self::bearer($req);
        if (!$token) {
            Response::error('Missing bearer token.', 401);
        }
        $tok = Db::t('api_tokens');
        $sql = "SELECT u.id, u.user_id, u.real_name, u.email, u.role_id, k.store_code
                FROM $tok k JOIN " . TB_PREF . "users u ON u.id = k.user_id
                WHERE k.token = " . Db::esc($token) . " AND k.expires_at > NOW() LIMIT 1";
        $row = Db::row($sql);
        if (!$row) {
            Response::error('Invalid or expired token.', 401);
        }
        return $row;
    }

    /** Validate identifier (FA login or email) + password against 0_users. */
    public static function validateCredentials($identifier, $password)
    {
        $row = Db::row("SELECT * FROM " . TB_PREF . "users
            WHERE inactive = 0 AND (user_id = " . Db::esc($identifier) . " OR email = " . Db::esc($identifier) . ")
            LIMIT 1");
        if (!$row) {
            return null;
        }
        if (self::verifyPassword($password, $row['password'])) {
            return $row;
        }
        return null;
    }

    /** Supports FA legacy md5 hashes and modern password_hash() hashes. */
    public static function verifyPassword($plain, $hash)
    {
        if (preg_match('/^[a-f0-9]{32}$/i', $hash)) {
            return md5($plain) === strtolower($hash);
        }
        return password_verify($plain, $hash);
    }

    /** Create and persist a new token for an FA user + store. */
    public static function issueToken($userId, $storeCode)
    {
        global $AVOGS_CFG;
        $token = bin2hex(openssl_random_pseudo_bytes(20));
        $ttl = (int) $AVOGS_CFG['token_ttl_days'];
        $tok = Db::t('api_tokens');
        Db::exec("INSERT INTO $tok (token, user_id, store_code, created_at, expires_at)
                  VALUES (" . Db::esc($token) . ", " . (int) $userId . ", " . Db::esc($storeCode, true) . ",
                  NOW(), DATE_ADD(NOW(), INTERVAL $ttl DAY))");
        return $token;
    }
}
