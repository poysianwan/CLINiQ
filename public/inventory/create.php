<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare(
        'INSERT INTO inventory_items (item_name, category, quantity, unit, reorder_level, expiration_date) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['item_name'] ?? ''),
        trim($_POST['category'] ?? ''),
        (int)($_POST['quantity'] ?? 0),
        trim($_POST['unit'] ?? 'pcs'),
        (int)($_POST['reorder_level'] ?? 0),
        $_POST['expiration_date'] ?: null,
    ]);

    flash_message('success', '"' . trim($_POST['item_name']) . '" added to inventory.');
    header('Location: index.php');
    exit;
}

header('Location: index.php');
