<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('
        UPDATE inventory_items
        SET archived_at = NULL, archived_reason = NULL, archived_by = NULL
        WHERE id = ? AND archived_at IS NOT NULL
    ');
    $stmt->execute([$id]);

    flash_message('success', 'Inventory item restored to active inventory.');
    header('Location: index.php?tab=medicine');
    exit;
}

header('Location: index.php?tab=archived');
