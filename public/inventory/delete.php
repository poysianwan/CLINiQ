<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare('DELETE FROM inventory_items WHERE id = ?');
    $stmt->execute([$id]);

    flash_message('success', 'Inventory item removed.');
    header('Location: index.php');
    exit;
}

header('Location: index.php');
