<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/InventoryWorkflow.php';
require_login();
ensure_inventory_workflow_schema();

$allItems = db()->query('
    SELECT i.*, u.name AS archived_by_name
    FROM inventory_items i
    LEFT JOIN users u ON u.id = i.archived_by
    ORDER BY COALESCE(i.archived_at, i.updated_at, i.created_at) DESC, i.item_name
')->fetchAll();

$activeItems = array_values(array_filter($allItems, fn(array $item): bool => empty($item['archived_at'])));
$archivedItems = array_values(array_filter($allItems, fn(array $item): bool => !empty($item['archived_at'])));
$equipmentItems = array_values(array_filter($activeItems, fn(array $item): bool => str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment')));
$medicineItems = array_values(array_filter($activeItems, fn(array $item): bool => !str_contains(strtolower((string) ($item['category'] ?? '')), 'equipment')));
$lowStock = array_values(array_filter($activeItems, fn(array $item): bool => (int) $item['quantity'] <= (int) $item['reorder_level']));
$expiring = array_values(array_filter($activeItems, fn(array $item): bool => $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days')));
$outOfStock = array_values(array_filter($activeItems, fn(array $item): bool => (int) $item['quantity'] === 0));

$activeTab = $_GET['tab'] ?? 'medicine';
if (!in_array($activeTab, ['medicine', 'equipment', 'expiring', 'archived'], true)) {
    $activeTab = 'medicine';
}

$activeColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Stock Level', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Reorder At', 'field' => 'reorderLevel', 'minWidth' => 120, 'flex' => 0.6],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 120, 'flex' => 0.55],
];

$archivedColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 230, 'flex' => 1.2],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 150, 'flex' => 0.8],
    ['headerName' => 'Final Stock', 'field' => 'quantityHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'minWidth' => 140, 'flex' => 0.7],
    ['headerName' => 'Archived', 'field' => 'archivedHtml', 'cellRenderer' => 'html', 'minWidth' => 180, 'flex' => 0.9],
    ['headerName' => 'Reason', 'field' => 'reasonHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1.1],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 120, 'flex' => 0.55],
];

$equipmentColumns = [
    ['headerName' => 'Equipment', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 260, 'flex' => 1.3],
    ['headerName' => 'Category', 'field' => 'categoryHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Available', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'minWidth' => 220, 'flex' => 1],
    ['headerName' => 'Reorder At', 'field' => 'reorderLevel', 'minWidth' => 120, 'flex' => 0.6],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'minWidth' => 160, 'flex' => 0.8],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'minWidth' => 120, 'flex' => 0.55],
];

$visibleItems = match ($activeTab) {
    'expiring' => $expiring,
    'equipment' => $equipmentItems,
    'archived' => $archivedItems,
    default => $medicineItems,
};

$inventoryRows = [];
foreach ($visibleItems as $item) {
    $isArchived = !empty($item['archived_at']);
    $isLow = (int) $item['quantity'] <= (int) $item['reorder_level'];
    $isExpiring = $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days');
    $maxQty = max((int) $item['reorder_level'] * 4, (int) $item['quantity'], 1);
    $pct = min(100, round(((int) $item['quantity'] / $maxQty) * 100));
    $barClass = $pct <= 20 ? 'stock-critical' : ($pct <= 50 ? 'stock-warning' : 'stock-healthy');
    $category = trim((string) ($item['category'] ?? ''));
    $expirationLabel = $item['expiration_date'] ? date('M Y', strtotime($item['expiration_date'])) : '-';
    $expirationClass = $isExpiring && !$isArchived ? 'text-red-600' : 'text-slate-600';
    $editArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
        e(json_encode($item['category'])),
        (int) $item['quantity'],
        e(json_encode($item['unit'])),
        (int) $item['reorder_level'],
        e(json_encode($item['expiration_date'])),
    ]);
    $archiveArgs = implode(', ', [
        (int) $item['id'],
        e(json_encode($item['item_name'])),
    ]);

    if ($isArchived) {
        $restoreMessage = e('Restore ' . $item['item_name'] . ' to active inventory?');
        $inventoryRows[] = [
            'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">Archived inventory record</p></div>',
            'categoryHtml' => '<span class="badge badge-cancelled">' . e($category !== '' ? $category : 'Uncategorized') . '</span>',
            'quantityHtml' => '<span class="text-sm font-bold text-slate-700">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span>',
            'expirationHtml' => '<span class="text-sm font-bold text-slate-500">' . e($expirationLabel) . '</span>',
            'archivedHtml' => '<div><strong class="text-sm text-slate-700">' . e(date('M d, Y', strtotime($item['archived_at']))) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($item['archived_by_name'] ?: 'System') . '</p></div>',
            'reasonHtml' => '<span class="text-sm font-bold text-slate-500">' . e($item['archived_reason'] ?: 'No reason recorded') . '</span>',
            'actionsHtml' => '<form method="post" action="restore.php" class="flex justify-end" data-confirm-submit data-confirm-type="primary" data-confirm-title="Restore inventory item?" data-confirm-message="' . $restoreMessage . '" data-confirm-toast="Restoring inventory item..."><input type="hidden" name="id" value="' . (int) $item['id'] . '"><button type="submit" class="btn btn-sm btn-outline"><span class="material-symbols-outlined text-[14px]">restore</span>Restore</button></form>',
        ];
        continue;
    }

    $inventoryRows[] = [
        'itemHtml' => '<div><strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong><p class="text-xs font-bold text-slate-400 mb-0">' . e($category !== '' ? $category : 'No category') . '</p></div>',
        'categoryHtml' => '<span class="text-sm font-bold text-slate-600">' . e($category !== '' ? $category : '-') . '</span>',
        'stockHtml' => '<div class="flex items-center gap-2"><span class="stock-bar"><span class="stock-bar-fill ' . e($barClass) . '" style="width: ' . (int) $pct . '%"></span></span><span class="text-sm font-bold ' . ($isLow ? 'text-amber-600' : 'text-slate-600') . '">' . (int) $item['quantity'] . ' ' . e($item['unit']) . '</span></div>',
        'reorderLevel' => (int) $item['reorder_level'],
        'expirationHtml' => '<span class="text-sm font-bold ' . $expirationClass . '">' . e($expirationLabel) . '</span>',
        'statusHtml' => inventory_status_badge($item),
        'actionsHtml' => '<div class="flex justify-end gap-1"><button onclick="editItem(' . $editArgs . ')" class="btn-icon btn-icon-primary" title="Edit item"><span class="material-symbols-outlined">edit</span></button><button onclick="openArchiveItem(' . $archiveArgs . ')" class="btn-icon btn-icon-slate" title="Archive item"><span class="material-symbols-outlined">archive</span></button></div>',
    ];
}

$tableTitle = match ($activeTab) {
    'equipment' => 'Equipment Tracking',
    'expiring' => 'Expiring Soon',
    'archived' => 'Archived Inventory',
    default => 'Medicine Inventory',
};
$tableDescription = match ($activeTab) {
    'equipment' => count($equipmentItems) . ' active equipment item(s) available for clinic use.',
    'expiring' => count($expiring) . ' active item(s) expiring within 30 days.',
    'archived' => count($archivedItems) . ' item(s) removed from active inventory.',
    default => count($medicineItems) . ' active medicine and supply item(s).',
};
$gridColumns = match ($activeTab) {
    'archived' => $archivedColumns,
    'equipment' => $equipmentColumns,
    default => $activeColumns,
};

render_header('Inventory');
?>

<div class="flex flex-col xl:flex-row justify-between items-start xl:items-end gap-6 mb-8">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">Supplies</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]">Inventory & Tracking</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage clinic medicines, supplies, expiring items, and archived records.</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
        <div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[150px] shadow-sm">
            <span class="material-symbols-outlined text-emerald-500 text-[28px]">check_circle</span>
            <div>
                <p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1"><?= count($activeItems) - count($outOfStock) ?></p>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">In stock</p>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[150px] shadow-sm">
            <span class="material-symbols-outlined text-amber-500 text-[28px]">warning</span>
            <div>
                <p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1"><?= count($lowStock) ?></p>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Low stock</p>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-4 flex items-center gap-4 min-w-[150px] shadow-sm">
            <span class="material-symbols-outlined text-red-500 text-[28px]">event_busy</span>
            <div>
                <p class="font-headline text-2xl font-extrabold text-[#17261d] leading-none mb-1"><?= count($expiring) ?></p>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest leading-none">Expiring soon</p>
            </div>
        </div>
    </div>
</div>

<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= e($tableTitle) ?></h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= e($tableDescription) ?></p>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 w-full lg:w-auto">
                <div class="search-input-wrap w-full lg:w-[360px] shrink-0">
                    <span class="search-icon material-symbols-outlined">search</span>
                    <input id="inventoryGridSearch" type="text" placeholder="Search inventory..." class="search-input">
                </div>
                <div class="flex flex-wrap gap-3 shrink-0">
                    <button onclick="showModal('addMedicineModal')" class="btn btn-primary">
                        <span class="material-symbols-outlined text-[20px]">medication</span>
                        + Medicine
                    </button>
                    <button onclick="showModal('addEquipmentModal')" class="btn btn-outline">
                        <span class="material-symbols-outlined text-[20px]">medical_services</span>
                        + Equipment
                    </button>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <a href="?tab=medicine" class="status-tab <?= $activeTab === 'medicine' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">pill</span>
                Medicine Inventory
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'medicine' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($medicineItems) ?></span>
            </a>
            <a href="?tab=equipment" class="status-tab <?= $activeTab === 'equipment' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">medical_services</span>
                Equipment Tracking
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'equipment' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($equipmentItems) ?></span>
            </a>
            <a href="?tab=expiring" class="status-tab <?= $activeTab === 'expiring' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">event_busy</span>
                Expiring Soon
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'expiring' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($expiring) ?></span>
            </a>
            <a href="?tab=archived" class="status-tab <?= $activeTab === 'archived' ? 'active' : '' ?> text-decoration-none">
                <span class="material-symbols-outlined text-[18px] align-middle mr-1">archive</span>
                Archived
                <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $activeTab === 'archived' ? 'bg-primary-fixed text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= count($archivedItems) ?></span>
            </a>
        </div>
    </div>

    <?php render_ag_grid('inventoryGrid', $gridColumns, $inventoryRows, [
        'searchInput' => 'inventoryGridSearch',
        'emptyTitle' => match ($activeTab) {
            'equipment' => 'No equipment items',
            'expiring' => 'No expiring items',
            'archived' => 'No archived records',
            default => 'No inventory items',
        },
        'emptyText' => match ($activeTab) {
            'equipment' => 'Add clinic equipment to track active stock and availability.',
            'expiring' => 'No active items are expiring within the next 30 days.',
            'archived' => 'Archived medicine and supply records will appear here.',
            default => 'Add medicines and supplies to start tracking.',
        },
    ]); ?>
</section>

<div id="addMedicineModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Add Medicine / Supply</h3>
            <button onclick="closeModal('addMedicineModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="create.php">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Paracetamol 500mg">
                </div>
                <div>
                    <label class="clinic-label">Category</label>
                    <input class="clinic-input" name="category" placeholder="e.g. Analgesic">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" value="pcs" required placeholder="e.g. Tablets, Capsules">
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="quantity" type="number" min="0" required value="0">
                </div>
                <div>
                    <label class="clinic-label">Reorder Level</label>
                    <input class="clinic-input" name="reorder_level" type="number" min="0" required value="10">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Expiration Date</label>
                    <input class="clinic-input" name="expiration_date" type="date">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addMedicineModal')" class="btn btn-ghost btn-cancel-icon" title="Cancel" aria-label="Cancel">
                    <span class="material-symbols-outlined">cancel</span>
                </button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add this inventory item?" data-confirm-message="This will save the new medicine or supply item to active inventory." data-confirm-toast="Adding inventory item...">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<div id="addEquipmentModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Add Equipment</h3>
            <button onclick="closeModal('addEquipmentModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="create.php">
            <input type="hidden" name="category" value="Equipment">
            <input type="hidden" name="expiration_date" value="">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="clinic-label">Equipment Name</label>
                    <input class="clinic-input" name="item_name" required placeholder="e.g. Pulse Oximeter">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" value="unit" required placeholder="e.g. unit, set, pcs">
                </div>
                <div>
                    <label class="clinic-label">Available Quantity</label>
                    <input class="clinic-input" name="quantity" type="number" min="0" required value="0">
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Low Stock Alert At</label>
                    <input class="clinic-input" name="reorder_level" type="number" min="0" required value="1">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('addEquipmentModal')" class="btn btn-ghost btn-cancel-icon" title="Cancel" aria-label="Cancel">
                    <span class="material-symbols-outlined">cancel</span>
                </button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Add this equipment?" data-confirm-message="This will save the equipment item to active inventory." data-confirm-toast="Adding equipment...">
                    <span class="material-symbols-outlined text-[18px]">add</span>
                    Add Equipment
                </button>
            </div>
        </form>
    </div>
</div>

<div id="editMedicineModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Edit Item</h3>
            <button onclick="closeModal('editMedicineModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="update.php" id="editItemForm">
            <input type="hidden" name="id" id="editItemId">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="clinic-label">Item Name</label>
                    <input class="clinic-input" name="item_name" id="editItemName" required>
                </div>
                <div>
                    <label class="clinic-label">Category</label>
                    <input class="clinic-input" name="category" id="editItemCategory">
                </div>
                <div>
                    <label class="clinic-label">Unit</label>
                    <input class="clinic-input" name="unit" id="editItemUnit" required>
                </div>
                <div>
                    <label class="clinic-label">Quantity</label>
                    <input class="clinic-input" name="quantity" id="editItemQuantity" type="number" min="0" required>
                </div>
                <div>
                    <label class="clinic-label">Reorder Level</label>
                    <input class="clinic-input" name="reorder_level" id="editItemReorder" type="number" min="0" required>
                </div>
                <div class="md:col-span-2">
                    <label class="clinic-label">Expiration Date</label>
                    <input class="clinic-input" name="expiration_date" id="editItemExpiry" type="date">
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('editMedicineModal')" class="btn btn-ghost btn-cancel-icon" title="Cancel" aria-label="Cancel">
                    <span class="material-symbols-outlined">cancel</span>
                </button>
                <button type="submit" class="btn btn-primary" data-confirm-submit data-confirm-type="primary" data-confirm-title="Save inventory changes?" data-confirm-message="This will update the selected inventory item." data-confirm-toast="Saving inventory changes...">
                    <span class="material-symbols-outlined text-[18px]">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<div id="archiveItemModal" class="modal-backdrop">
    <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-lg shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="font-headline text-xl font-extrabold text-[#1c2a59]">Archive Inventory Item</h3>
                <p class="text-sm font-bold text-slate-500 mt-1" id="archiveItemName">Move this item out of active inventory.</p>
            </div>
            <button onclick="closeModal('archiveItemModal')" class="btn-icon btn-icon-slate">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <form method="post" action="archive.php" id="archiveItemForm">
            <input type="hidden" name="id" id="archiveItemId">
            <label class="clinic-label">Archive Reason</label>
            <textarea class="clinic-textarea" name="archived_reason" rows="4" required placeholder="e.g. Expired batch, damaged item, duplicate record, depleted supply"></textarea>
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" onclick="closeModal('archiveItemModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-danger" data-confirm-submit data-confirm-type="danger" data-confirm-title="Archive this item?" data-confirm-message="This will remove the item from active inventory and move it to archived records." data-confirm-toast="Archiving inventory item...">
                    <span class="material-symbols-outlined text-[18px]">archive</span>
                    Archive Item
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editItem(id, name, category, quantity, unit, reorder, expiry) {
    document.getElementById('editItemId').value = id;
    document.getElementById('editItemName').value = name;
    document.getElementById('editItemCategory').value = category || '';
    document.getElementById('editItemQuantity').value = quantity;
    document.getElementById('editItemUnit').value = unit;
    document.getElementById('editItemReorder').value = reorder;
    document.getElementById('editItemExpiry').value = expiry || '';
    showModal('editMedicineModal');
}

function openArchiveItem(id, name) {
    document.getElementById('archiveItemId').value = id;
    document.getElementById('archiveItemName').textContent = name ? `Move ${name} out of active inventory.` : 'Move this item out of active inventory.';
    showModal('archiveItemModal');
}
</script>

<?php render_footer(); ?>
