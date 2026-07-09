<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

// -- Fetch inventory data ------------------------------------
$items = db()->query('SELECT * FROM inventory_items ORDER BY item_name')->fetchAll();
$lowStock = array_filter($items, fn($item) => (int) $item['quantity'] <= (int) $item['reorder_level']);
$expiring = array_filter($items, fn($item) => $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days'));
$outOfStock = array_filter($items, fn($item) => (int) $item['quantity'] === 0);

$activeTab = $_GET['tab'] ?? 'medicine';

$inventoryColumns = [
    ['headerName' => 'Item', 'field' => 'itemHtml', 'cellRenderer' => 'html', 'minWidth' => 220],
    ['headerName' => 'Category', 'field' => 'category', 'minWidth' => 160],
    ['headerName' => 'Stock Level', 'field' => 'stockHtml', 'cellRenderer' => 'html', 'minWidth' => 230],
    ['headerName' => 'Reorder At', 'field' => 'reorderLevel', 'width' => 130],
    ['headerName' => 'Expiration', 'field' => 'expirationHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Status', 'field' => 'statusHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Actions', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 130],
];
$inventoryRows = [];
foreach ($items as $item) {
    $isLow = (int)$item['quantity'] <= (int)$item['reorder_level'];
    $isOut = (int)$item['quantity'] === 0;
    $isExpiring = $item['expiration_date'] && strtotime($item['expiration_date']) <= strtotime('+30 days');
    $maxQty = max((int)$item['reorder_level'] * 4, (int)$item['quantity'], 1);
    $pct = min(100, round(((int)$item['quantity'] / $maxQty) * 100));
    $barClass = $pct <= 20 ? 'stock-critical' : ($pct <= 50 ? 'stock-warning' : 'stock-healthy');
    if ($isOut) {
        $status = '<span class="badge badge-critical">Out of Stock</span>';
    } elseif ($isLow) {
        $status = '<span class="badge badge-pending">Low Stock</span>';
    } elseif ($isExpiring) {
        $status = '<span class="badge badge-high">Expiring</span>';
    } else {
        $status = '<span class="badge badge-completed">In Stock</span>';
    }
    $editArgs = implode(', ', [
        (int)$item['id'],
        e(json_encode($item['item_name'])),
        e(json_encode($item['category'])),
        (int)$item['quantity'],
        e(json_encode($item['unit'])),
        (int)$item['reorder_level'],
        e(json_encode($item['expiration_date'])),
    ]);
    $deleteMessage = e(json_encode('Are you sure you want to delete ' . $item['item_name'] . '?'));
    $inventoryRows[] = [
        'itemHtml' => '<strong class="text-sm text-slate-800">' . e($item['item_name']) . '</strong>',
        'category' => $item['category'] ?: '-',
        'stockHtml' => '<div class="flex items-center gap-2"><span class="stock-bar"><span class="stock-bar-fill ' . e($barClass) . '" style="width: ' . (int)$pct . '%"></span></span><span class="text-sm font-bold ' . ($isLow ? 'text-amber-600' : 'text-slate-600') . '">' . (int)$item['quantity'] . ' ' . e($item['unit']) . '</span></div>',
        'reorderLevel' => (int)$item['reorder_level'],
        'expirationHtml' => $item['expiration_date'] ? '<span class="text-sm font-bold ' . ($isExpiring ? 'text-red-600' : 'text-slate-600') . '">' . e(date('M Y', strtotime($item['expiration_date']))) . '</span>' : '<span class="text-sm font-bold text-slate-400">-</span>',
        'statusHtml' => $status,
        'actionsHtml' => '<div class="flex justify-end gap-1"><button onclick="editItem(' . $editArgs . ')" class="btn-icon btn-icon-primary" title="Edit"><span class="material-symbols-outlined">edit</span></button><button onclick="confirmAction(\'Delete Item\', ' . $deleteMessage . ', () => { document.getElementById(\'deleteForm-' . (int)$item['id'] . '\').submit(); })" class="btn-icon btn-icon-danger" title="Delete"><span class="material-symbols-outlined">delete</span></button><form id="deleteForm-' . (int)$item['id'] . '" method="post" action="delete.php" style="display:none;"><input type="hidden" name="id" value="' . (int)$item['id'] . '"></form></div>',
    ];
}

render_header('Inventory');
?>

<!-- --- Title --- -->
<div class="dashboard-hero flex flex-col lg:flex-row lg:items-center justify-between gap-5 mb-8">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">Supplies</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]">Inventory & Tracking</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage clinic medicines, supplies, and low-stock warnings.</p>
    </div>
    <div class="flex gap-3">
        <button onclick="showModal('addMedicineModal')" class="btn btn-primary">
            <span class="material-symbols-outlined text-[20px]">medication</span>
            + Medicine
        </button>
    </div>
</div>

<!-- --- Tabs & Search --- -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mt-6">
    <div class="flex items-center gap-2 bg-slate-100 p-1 rounded-2xl w-fit">
        <button onclick="switchTab('medicine')" id="tab-medicine" class="tab-btn <?= $activeTab === 'medicine' ? 'active' : '' ?> px-6 py-3 rounded-2xl font-bold text-sm transition-all flex items-center gap-2 text-slate-500 hover:bg-slate-50">
            <span class="material-symbols-outlined text-[20px]">pill</span>
            Medicine Inventory
        </button>
        <button onclick="switchTab('expiring')" id="tab-expiring" class="tab-btn <?= $activeTab === 'expiring' ? 'active' : '' ?> px-6 py-3 rounded-2xl font-bold text-sm transition-all flex items-center gap-2 text-slate-500 hover:bg-slate-50">
            <span class="material-symbols-outlined text-[20px]">event_busy</span>
            Expiring Soon
        </button>
    </div>
    
    <div class="search-input-wrap w-full md:w-auto shrink-0 bg-white shadow-sm rounded-xl border border-outline-variant/20" style="min-width: 280px; padding: 0.5rem 1rem; display: flex; align-items: center; gap: 0.5rem;">
        <span class="search-icon material-symbols-outlined text-slate-400">search</span>
        <input id="inventoryGridSearch" type="text" placeholder="Search inventory..." class="search-input bg-transparent border-none outline-none w-full text-sm text-slate-700">
    </div>
</div>

<!-- --- Medicine Tab --- -->
<div id="medicine-content" class="tab-content <?= $activeTab === 'medicine' ? 'active' : '' ?> space-y-6 mt-6">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <span class="material-symbols-outlined">check_circle</span>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">In Stock</p>
                <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($items) - count($outOfStock) ?> Items</p>
            </div>
        </div>
        <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <span class="material-symbols-outlined">warning</span>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Low Stock</p>
                <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($lowStock) ?> Items</p>
            </div>
        </div>
        <div class="bg-white p-6 rounded-[2rem] border border-outline-variant/20 shadow-sm flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
                <span class="material-symbols-outlined">error</span>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Expiring Soon</p>
                <p class="text-2xl font-headline font-extrabold text-slate-800"><?= count($expiring) ?> Items</p>
            </div>
        </div>
    </div>

    <!-- Medicine Table -->
    <div class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
        <?php render_ag_grid('inventoryGrid', $inventoryColumns, $inventoryRows, [
            'searchInput' => 'inventoryGridSearch',
            'emptyTitle' => 'No inventory items',
            'emptyText' => 'Add medicines and supplies to start tracking.',
        ]); ?>
    </div>
</div>

<!-- --- Expiring Tab --- -->
<div id="expiring-content" class="tab-content <?= $activeTab === 'expiring' ? 'active' : '' ?> space-y-6 mt-6">
    <div class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Items Expiring Within 30 Days</h2>
            <p class="text-xs font-bold text-slate-500"><?= count($expiring) ?> item(s) need attention</p>
        </div>
        <?php if ($expiring): ?>
        <div class="divide-y divide-outline-variant/10">
            <?php foreach ($expiring as $item): ?>
            <div class="p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                        <span class="material-symbols-outlined text-[20px]">event_busy</span>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-800"><?= e($item['item_name']) ?></p>
                        <p class="text-xs font-bold text-slate-400"><?= (int)$item['quantity'] ?> <?= e($item['unit']) ?> remaining</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="badge badge-critical">
                        Expires <?= e(date('M d, Y', strtotime($item['expiration_date']))) ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="material-symbols-outlined">check_circle</span>
                <p class="empty-state-title">All clear</p>
                <p class="empty-state-text">No items expiring within the next 30 days.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- --- Add Medicine Modal --- -->
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
                <button type="button" onclick="closeModal('addMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined text-[18px]">add</span> Add Item
                </button>
            </div>
        </form>
    </div>
</div>

<!-- --- Edit Medicine Modal --- -->
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
                <button type="button" onclick="closeModal('editMedicineModal')" class="btn btn-ghost">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined text-[18px]">save</span> Save Changes
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
</script>

<?php render_footer(); ?>
