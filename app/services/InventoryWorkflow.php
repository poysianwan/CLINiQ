<?php

function ensure_inventory_workflow_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $db = db();
    $stmt = $db->query('SHOW COLUMNS FROM inventory_items');
    $columns = [];
    foreach ($stmt->fetchAll() as $column) {
        $columns[$column['Field']] = $column;
    }

    $addColumn = function (string $name, string $definition) use ($db, &$columns): void {
        if (!isset($columns[$name])) {
            $db->exec("ALTER TABLE inventory_items ADD COLUMN {$name} {$definition}");
            $columns[$name] = ['Field' => $name];
        }
    };

    $addColumn('archived_at', 'DATETIME NULL AFTER expiration_date');
    $addColumn('archived_reason', 'VARCHAR(255) NULL AFTER archived_at');
    $addColumn('archived_by', 'INT NULL AFTER archived_reason');
    $addColumn('updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at');

    $ready = true;
}

function inventory_status_badge(array $item): string
{
    $quantity = (int) ($item['quantity'] ?? 0);
    $reorderLevel = (int) ($item['reorder_level'] ?? 0);
    $expirationDate = $item['expiration_date'] ?? null;
    $isExpiring = $expirationDate && strtotime($expirationDate) <= strtotime('+30 days');

    if ($quantity === 0) {
        return '<span class="badge badge-critical">Out of Stock</span>';
    }
    if ($quantity <= $reorderLevel) {
        return '<span class="badge badge-pending">Low Stock</span>';
    }
    if ($isExpiring) {
        return '<span class="badge badge-high">Expiring Soon</span>';
    }

    return '<span class="badge badge-completed">In Stock</span>';
}
