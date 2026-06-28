<?php
/** JSON response helper. Clears any FA output buffers before sending. */
class Response
{
    public static function json($data, $status = 200)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode($data);
        exit;
    }

    public static function error($message, $status = 400, $code = null)
    {
        if ($code === null) {
            $map = array(
                400 => 'bad_request', 401 => 'unauthorized', 403 => 'forbidden',
                404 => 'not_found', 409 => 'conflict', 422 => 'validation_error',
                500 => 'server_error',
            );
            $code = isset($map[$status]) ? $map[$status] : 'error';
        }
        self::json(array('error' => array('code' => $code, 'message' => $message)), $status);
    }
}
