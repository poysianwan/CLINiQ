<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $reason = trim((string) ($_POST['archived_reason'] ?? ''));
    $user = current_user();

    $stmt = db()->prepare('
        UPDATE inventory_items
        SET archived_at = NOW(), archived_reason = ?, archived_by = ?
        WHERE id = ? AND archived_at IS NULL
    ');
    $stmt->execute([
        $reason !== '' ? $reason : 'Archived from inventory',
        (int) ($user['id'] ?? 0) ?: null,
        $id,
    ]);

    flash_message('success', 'Inventory item archived.');
    header('Location: index.php?tab=archived');
    exit;
}

header('Location: index.php');
