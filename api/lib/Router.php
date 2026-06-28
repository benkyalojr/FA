<?php
/** Tiny path/method router with {param} placeholders. */
class Router
{
    private $routes = array();

    public function add($method, $pattern, $handler)
    {
        // /sales/invoices/{id} -> regex with named-ish capture groups.
        $names = array();
        $regex = preg_replace_callback('#\{([a-zA-Z_]+)\}#', function ($m) use (&$names) {
            $names[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . $regex . '$#';
        $this->routes[] = array(
            'method' => strtoupper($method),
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
        );
    }

    public function get($p, $h)    { $this->add('GET', $p, $h); }
    public function post($p, $h)   { $this->add('POST', $p, $h); }
    public function put($p, $h)    { $this->add('PUT', $p, $h); }
    public function delete($p, $h) { $this->add('DELETE', $p, $h); }

    public function dispatch(Request $req)
    {
        if ($req->method === 'OPTIONS') {
            Response::json(null, 204);
        }

        $pathMatched = false;
        foreach ($this->routes as $r) {
            if (preg_match($r['regex'], $req->path, $m)) {
                $pathMatched = true;
                if ($r['method'] !== $req->method) {
                    continue;
                }
                $params = array();
                for ($i = 0; $i < count($r['names']); $i++) {
                    $params[$r['names'][$i]] = urldecode($m[$i + 1]);
                }
                return call_user_func($r['handler'], $req, $params);
            }
        }

        if ($pathMatched) {
            Response::error('Method not allowed for this resource.', 405, 'method_not_allowed');
        }
        Response::error('No such endpoint: ' . $req->path, 404);
    }
}
