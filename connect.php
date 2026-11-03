<?php
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'medbot');
define('DB_SSL',  filter_var(getenv('DB_SSL') ?: 'false', FILTER_VALIDATE_BOOLEAN));

function getDB(): mysqli {
    static $conn = null;
    if ($conn && $conn->ping()) return $conn;

    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = mysqli_init();

    $flags = 0;
    if (DB_SSL) {
        // Use the system CA bundle (present in the php:8.2-apache image)
        $conn->ssl_set(null, null, '/etc/ssl/certs/ca-certificates.crt', null, null);
        $flags = MYSQLI_CLIENT_SSL;
    }

    if (!@$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, $flags)) {
        http_response_code(500);
        die(json_encode(['error' => true, 'message' => 'DB connection failed: ' . $conn->connect_error]));
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
