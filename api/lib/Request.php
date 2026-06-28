<?php
/** Parsed HTTP request: method, path, headers, query and JSON body. */
class Request
{
    public $method;
    public $path;
    public $query;
    public $body;
    public $headers;

    public function __construct()
    {
        $this->method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            $path = '/';
        }
        // Normalise: drop a leading /api and any /index.php, trim trailing slash.
        $path = preg_replace('#^/api#', '', $path);
        $path = preg_replace('#/index\.php#', '', $path);
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        $this->path = $path;

        $this->query = $_GET;
        $this->headers = $this->readHeaders();

        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $this->body = is_array($decoded) ? $decoded : array();
    }

    private function readHeaders()
    {
        $headers = array();
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    public function header($name)
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return null;
    }

    /** Body field with a fallback default. */
    public function input($key, $default = null)
    {
        return array_key_exists($key, $this->body) ? $this->body[$key] : $default;
    }

    /** Query-string param with a fallback default. */
    public function q($key, $default = null)
    {
        return isset($this->query[$key]) ? $this->query[$key] : $default;
    }
}
