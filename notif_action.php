<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$user = require_auth($pdo);
header('Content-Type: application/json');

$action = $_REQUEST['action'] ?? '';

// ── Helper: get unread count ─────────────────────────────────────────────────
function notif_unread_count(PDO $pdo, int $uid): int {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
         WHERE nr.id IS NULL
           AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, CAST(? AS JSON))))
           AND (n.expires_at IS NULL OR n.expires_at > NOW())"
    );
    $s->execute([$uid, $uid]);
    return (int)$s->fetchColumn();
}

// ── Count unread ──────────────────────────────────────────────────────────────
if ($action === 'count') {
    echo json_encode(['count' => notif_unread_count($pdo, $user['id'])]);
    exit;
}

// ── Mark single as read ───────────────────────────────────────────────────────
if ($action === 'mark_read' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?,?)")
        ->execute([$id, $user['id']]);
    echo json_encode(['ok' => true, 'count' => notif_unread_count($pdo, $user['id'])]);
    exit;
}

// ── Mark all as read ──────────────────────────────────────────────────────────
if ($action === 'mark_all') {
    $notifs = $pdo->prepare(
        "SELECT id FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
         WHERE nr.id IS NULL
           AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, CAST(? AS JSON))))
           AND (n.expires_at IS NULL OR n.expires_at > NOW())"
    );
    $notifs->execute([$user['id'], $user['id']]);
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?,?)");
    foreach ($notifs->fetchAll() as $n) {
        $stmt->execute([$n['id'], $user['id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['error' => 'unknown action']);
