<?php
function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data, array $meta = null, int $status = 200) {
    $res = ['success' => true, 'data' => $data];
    if ($meta) $res['meta'] = $meta;
    jsonResponse($res, $status);
}

function created($data) {
    ok($data, null, 201);
}

function err(string $code, string $message, int $status = 400, array $details = null) {
    $res = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
    if ($details) $res['error']['details'] = $details;
    jsonResponse($res, $status);
}

function unauthorized(string $msg = 'Authentication required') { err('UNAUTHORIZED', $msg, 401); }
function forbidden(string $msg = 'Insufficient permissions') { err('FORBIDDEN', $msg, 403); }
function notFound(string $msg = 'Resource not found') { err('NOT_FOUND', $msg, 404); }
function conflict(string $msg = 'Resource already exists') { err('CONFLICT', $msg, 409); }
function tooManyReqs(string $msg = 'Too many requests. Try later.') { err('RATE_LIMITED', $msg, 429); }
function badRequest(string $msg, array $details = null) { err('BAD_REQUEST', $msg, 400, $details); }
function serverError(string $msg = 'Internal server error') { err('SERVER_ERROR', $msg, 500); }

function validationError(array $errors) {
    badRequest('Validation failed', $errors);
}
