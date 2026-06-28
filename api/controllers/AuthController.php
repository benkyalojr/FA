<?php
class AuthController
{
    public static function login(Request $req)
    {
        $identifier = trim((string) $req->input('identifier', ''));
        $password = (string) $req->input('password', '');
        if ($identifier === '' || $password === '') {
            Response::error('identifier and password are required.', 422);
        }
        $user = Auth::validateCredentials($identifier, $password);
        if (!$user) {
            Response::error('Invalid credentials.', 401);
        }
        $store = $req->input('store', null);
        $token = Auth::issueToken($user['id'], $store);

        $stores = Db::rows("SELECT location_name FROM " . TB_PREF . "locations WHERE inactive = 0 ORDER BY location_name");
        $allowed = array();
        foreach ($stores as $s) {
            $allowed[] = $s['location_name'];
        }
        Response::json(array(
            'token' => $token,
            'user' => array(
                'id' => (int) $user['id'],
                'login' => $user['user_id'],
                'name' => $user['real_name'],
                'role_id' => (int) $user['role_id'],
            ),
            'allowed_stores' => $allowed,
        ));
    }

    public static function logout(Request $req)
    {
        $token = Auth::bearer($req);
        if ($token) {
            Db::exec("DELETE FROM " . Db::t('api_tokens') . " WHERE token = " . Db::esc($token));
        }
        Response::json(null, 204);
    }
}
