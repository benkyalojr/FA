<?php
class UploadController
{
    public static function create(Request $req)
    {
        Auth::requireUser($req);
        global $AVOGS_CFG;
        $dir = $AVOGS_CFG['upload_dir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            Response::error('Expected a multipart file field named "file".', 422);
        }
        $id = 'upl_' . bin2hex(openssl_random_pseudo_bytes(6));
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        if ($ext === '') { $ext = 'jpg'; }
        $filename = $id . '.' . $ext;
        $dest = rtrim($dir, '/') . '/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            Response::error('Failed to store the upload.', 500);
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $url = $scheme . '://' . $host . '/api/storage/' . $filename;

        $now = date('Y-m-d H:i:s');
        Db::exec("INSERT INTO " . Db::t('uploads') . " (upload_id, path, url, created_at) VALUES ("
            . Db::esc($id) . ", " . Db::esc($dest) . ", " . Db::esc($url) . ", " . Db::esc($now) . ")");

        Response::json(array('upload_id' => $id, 'url' => $url), 201);
    }
}
