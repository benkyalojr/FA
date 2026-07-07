<?php
class UploadController
{
    /** Human-readable PHP upload error codes for api.log / mobile debugging. */
    private static function upload_err_msg($code)
    {
        $map = array(
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize in php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE in the form.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing PHP temp folder (upload_tmp_dir).',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk (check permissions).',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        );
        return isset($map[$code]) ? $map[$code] : ('Upload error code ' . (int) $code);
    }

    public static function create(Request $req)
    {
        Auth::requireUser($req);
        global $AVOGS_CFG;
        $dir = $AVOGS_CFG['upload_dir'];
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            if (class_exists('Logger')) {
                Logger::error('Upload storage not writable', array('dir' => $dir));
            }
            Response::error('Upload storage is not writable on the server. Check api/storage permissions.', 500);
        }
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            foreach (array('image', 'photo') as $alt) {
                if (!empty($_FILES[$alt]) && is_uploaded_file($_FILES[$alt]['tmp_name'])) {
                    $_FILES['file'] = $_FILES[$alt];
                    break;
                }
            }
        }
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            $detail = 'Expected multipart field "file" (also "image" or "photo").';
            if (empty($_FILES)) {
                $detail .= ' $_FILES is empty — use Content-Type multipart/form-data, not JSON/base64.';
                $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
                if (class_exists('Logger')) {
                    Logger::warning('Upload rejected: no $_FILES', array(
                        'content_type' => $ct,
                        'content_length' => isset($_SERVER['CONTENT_LENGTH']) ? $_SERVER['CONTENT_LENGTH'] : null,
                    ));
                }
            } elseif (!empty($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $detail .= ' ' . self::upload_err_msg((int) $_FILES['file']['error']);
            }
            Response::error($detail, 422);
        }
        $id = 'upl_' . bin2hex(openssl_random_pseudo_bytes(6));
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', $ext);
        if ($ext === '') { $ext = 'jpg'; }
        $filename = $id . '.' . $ext;
        $dest = rtrim($dir, '/') . '/' . $filename;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
            if (class_exists('Logger')) {
                Logger::error('move_uploaded_file failed', array('dest' => $dest, 'dir_writable' => is_writable($dir)));
            }
            Response::error('Failed to store the upload. Check api/storage is writable by the web server.', 500);
        }
        if (class_exists('Logger')) {
            Logger::info('Upload stored', array('upload_id' => $id, 'bytes' => filesize($dest)));
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
