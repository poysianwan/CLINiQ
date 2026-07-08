<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['Pending', 'In Progress', 'Resolved', 'Cancelled'];

    if ($id > 0 && in_array($status, $allowed, true)) {
        $stmt = db()->prepare('UPDATE nurse_alerts SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        flash_message('success', 'Alert status updated to "' . $status . '".');
    }

    header('Location: index.php');
    exit;
}

header('Location: index.php');
