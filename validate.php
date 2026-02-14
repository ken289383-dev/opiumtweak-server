<?php
/**
 * ============================================================
 * OPIUM TWEAK – Key-Validierung (PHP + MySQL)
 * ============================================================
 * Deployment:
 *   1. Diese Datei auf deinen Webserver hochladen
 *   2. MySQL-Datenbank einrichten (Schema unten)
 *   3. DB-Zugangsdaten in $config eintragen
 *   4. URL in KeyValidator.cs eintragen
 *
 * Tabellen-Schema:
 *   CREATE TABLE license_keys (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     license_key VARCHAR(128) NOT NULL UNIQUE,
 *     hwid VARCHAR(128) DEFAULT NULL,
 *     activated_at DATETIME DEFAULT NULL,
 *     ip_address VARCHAR(45) DEFAULT NULL,
 *     active TINYINT(1) DEFAULT 1,
 *     created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *     notes TEXT DEFAULT NULL
 *   );
 *
 *   CREATE TABLE activation_log (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     license_key VARCHAR(128),
 *     hwid VARCHAR(128),
 *     ip_address VARCHAR(45),
 *     success TINYINT(1),
 *     message VARCHAR(255),
 *     attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
 *   );
 * ============================================================
 */

// ===== KONFIGURATION =====
$config = [
    'db_host'     => 'turntable.proxy.rlwy.net',
    'db_name'     => 'railway',
    'db_user'     => 'root',
    'db_pass'     => 'lkGYVksqoJixHAtIqbEnuMAuUEoOvBsE',
    'db_charset'  => 'utf8mb4',
    'db_port'     => 48636,
    'secret_salt' => 'OpiumTweak2025SuperGeheim!xZ9q',
];

// ===== HEADERS =====
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Nur POST-Anfragen akzeptieren
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method Not Allowed']));
}

// ===== INPUT VALIDIERUNG =====
$key  = trim($_POST['key']  ?? '');
$hwid = trim($_POST['hwid'] ?? '');

if (empty($key) || empty($hwid)) {
    die(json_encode(['success' => false, 'message' => 'Fehlende Parameter.']));
}

// Eingaben bereinigen
$key  = preg_replace('/[^A-Za-z0-9\-\_]/', '', $key);
$hwid = preg_replace('/[^A-Za-z0-9]/', '', $hwid);

if (strlen($key) < 4 || strlen($key) > 128) {
    die(json_encode(['success' => false, 'message' => 'Ungültiges Key-Format.']));
}
if (strlen($hwid) < 8 || strlen($hwid) > 128) {
    die(json_encode(['success' => false, 'message' => 'Ungültige HWID.']));
}

$ip = getClientIP();

// ===== DATENBANKVERBINDUNG =====
try {
    $dsn = "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_name']};charset={$config['db_charset']}";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log("OpiumTweak DB-Fehler: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Serverfehler. Bitte später erneut versuchen.']));
}

// ===== RATE LIMITING (einfach, IP-basiert) =====
$stmt = $pdo->prepare("SELECT COUNT(*) FROM activation_log WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
$stmt->execute([$ip]);
$attempts = $stmt->fetchColumn();

if ($attempts > 20) {
    logAttempt($pdo, $key, $hwid, $ip, false, 'Rate limit überschritten');
    die(json_encode(['success' => false, 'message' => 'Zu viele Versuche. Bitte 1 Stunde warten.']));
}

// ===== KEY PRÜFEN =====
$stmt = $pdo->prepare("SELECT * FROM license_keys WHERE license_key = ? LIMIT 1");
$stmt->execute([$key]);
$license = $stmt->fetch();

// Key nicht gefunden
if (!$license) {
    logAttempt($pdo, $key, $hwid, $ip, false, 'Key nicht gefunden');
    die(json_encode(['success' => false, 'message' => 'Ungültiger Lizenzschlüssel.']));
}

// Key deaktiviert (z.B. gesperrt)
if (!$license['active']) {
    logAttempt($pdo, $key, $hwid, $ip, false, 'Key deaktiviert');
    die(json_encode(['success' => false, 'message' => 'Dieser Lizenzschlüssel wurde deaktiviert.']));
}

// ===== HWID PRÜFEN =====

// Fall 1: Key noch keiner HWID zugeordnet → jetzt zuordnen
if (empty($license['hwid'])) {
    $stmt = $pdo->prepare("UPDATE license_keys SET hwid = ?, activated_at = NOW(), ip_address = ? WHERE license_key = ?");
    $stmt->execute([$hwid, $ip, $key]);
    logAttempt($pdo, $key, $hwid, $ip, true, 'Erstaktivierung erfolgreich');
    die(json_encode([
        'success' => true,
        'message' => 'Lizenz erfolgreich aktiviert! Willkommen bei Opium Tweak.'
    ]));
}

// Fall 2: Key bereits dieser HWID zugeordnet → OK (Re-Aktivierung)
if ($license['hwid'] === $hwid) {
    logAttempt($pdo, $key, $hwid, $ip, true, 'Re-Aktivierung erfolgreicht');
    die(json_encode([
        'success' => true,
        'message' => 'Lizenz verifiziert. Willkommen zurück!'
    ]));
}

// Fall 3: Key bereits einer anderen HWID zugeordnet → Betrug
logAttempt($pdo, $key, $hwid, $ip, false, 'HWID-Konflikt (Key bereits vergeben)');
die(json_encode([
    'success' => false,
    'message' => 'Dieser Key ist bereits auf einem anderen PC aktiviert. Kontaktiere den Support.'
]));


// ===== HILFSFUNKTIONEN =====

/**
 * Schreibt einen Aktivierungsversuch in die Log-Tabelle.
 */
function logAttempt(PDO $pdo, string $key, string $hwid, string $ip, bool $success, string $message): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO activation_log (license_key, hwid, ip_address, success, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$key, $hwid, $ip, $success ? 1 : 0, $message]);
    } catch (Exception $e) {
        error_log("OpiumTweak Log-Fehler: " . $e->getMessage());
    }
}

/**
 * Gibt die echte IP-Adresse des Clients zurück (berücksichtigt Proxies).
 */
function getClientIP(): string {
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}
