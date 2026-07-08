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

        $json = self::encodeJson($data);
        if ($json === false) {
            if (class_exists('Logger')) {
                Logger::error('json_encode failed', array(
                    'error' => json_last_error_msg(),
                    'status' => $status,
                ));
            }
            http_response_code(500);
            echo json_encode(array('error' => array(
                'code' => 'json_encode_failed',
                'message' => 'Could not encode API response.',
            )));
            exit;
        }

        echo $json;
        exit;
    }

    /** Encode payload as JSON; substitute invalid UTF-8 instead of failing silently. */
    private static function encodeJson($data)
    {
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($data, $flags);
        if ($json !== false) {
            return $json;
        }

        return json_encode(self::sanitizeUtf8($data), $flags);
    }

    /** Recursively strip/replace invalid UTF-8 in strings before json_encode. */
    private static function sanitizeUtf8($value)
    {
        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[is_string($k) ? self::cleanUtf8($k) : $k] = self::sanitizeUtf8($v);
            }
            return $out;
        }
        if (is_string($value)) {
            return self::cleanUtf8($value);
        }
        return $value;
    }

    private static function cleanUtf8($value)
    {
        if ($value === '' || preg_match('//u', $value)) {
            return $value;
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
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
        if (class_exists('Logger')) {
            // 5xx are real faults; 4xx are client/validation issues worth a warning.
            $ctx = array('status' => $status, 'code' => $code);
            if ($status >= 500) {
                Logger::error('Response ' . $status . ': ' . $message, $ctx);
            } else {
                Logger::warning('Response ' . $status . ': ' . $message, $ctx);
            }
        }
        self::json(array('error' => array('code' => $code, 'message' => $message)), $status);
    }
}
