<?php

declare(strict_types=1);

/**
 * Test HTTP server router for HttpHelper tests.
 *
 * Run: php -S 127.0.0.1:{port} -t {this-dir} {this-file}
 *
 * Endpoints:
 *   GET /       -> 200 text/plain "OK"
 *   GET /json   -> 200 application/json {"status":"ok","data":{"key":"value"}}
 *   POST /echo  -> 200 application/json {method, headers, body}
 *   POST /json  -> 200 application/json (echoes received JSON)
 *   GET /slow   -> 200 text/plain after 5-second delay (for timeout tests)
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/':
        header('Content-Type: text/plain');
        echo 'OK';
        break;

    case '/json':
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => ['key' => 'value']]);
        break;

    case '/echo':
        header('Content-Type: application/json');
        $body = file_get_contents('php://input');
        echo json_encode([
            'method'  => $_SERVER['REQUEST_METHOD'],
            'headers' => getallheaders(),
            'body'    => $body !== false ? json_decode($body, true) : null,
        ]);
        break;

    case '/slow':
        sleep(5);
        header('Content-Type: text/plain');
        echo 'slow response';
        break;

    default:
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Not Found';
        break;
}
