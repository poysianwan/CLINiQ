<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$filterStatus = $_GET['status'] ?? 'all';

$where = '1=1';
$params = [];
if ($filterStatus !== 'all') {
    $where = 'a.status = ?';
    $params[] = $filterStatus;
}

$alerts = db()->prepare("SELECT a.*, p.first_name, p.last_name FROM nurse_alerts a LEFT JOIN patients p ON p.id = a.patient_id WHERE {$where} ORDER BY a.created_at DESC LIMIT 100");
$alerts->execute($params);
$alerts = $alerts->fetchAll();

// Status counts
$statusCountQuery = db()->query("SELECT status, COUNT(*) AS cnt FROM nurse_alerts GROUP BY status");
$statusCounts = ['all' => 0];
foreach ($statusCountQuery->fetchAll() as $sc) {
    $statusCounts[strtolower($sc['status'])] = (int)$sc['cnt'];
    $statusCounts['all'] += (int)$sc['cnt'];
}

render_header('Nurse Alerts');
?>

<!-- ═══ Title ═══ -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Nurse Alerts</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Live emergency reports from staff and QR/NFC scans.</p>
    </div>
    <div class="flex items-center gap-3">
        <div class="flex items-center gap-2 text-xs font-semibold text-slate-400 bg-slate-100/50 px-3 py-1.5 rounded-full border border-slate-200/50">
            <span class="material-symbols-outlined text-[14px]">sync</span>
            Auto-refreshing every 5s
        </div>
        <a class="btn btn-danger text-decoration-none" href="create.php">
            <span class="material-symbols-outlined text-[20px]">emergency_home</span>
            Submit Alert
        </a>
    </div>
</div>

<!-- ═══ Alert Queue ═══ -->
<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Alert Queue</h2>
                <p class="text-xs font-bold text-slate-500 mb-0">Newest reports appear first.</p>
            </div>
        </div>

        <!-- Status Filter Tabs -->
        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $statusTabs = [
                'all' => 'All Alerts',
                'pending' => 'Pending',
                'in progress' => 'In Progress',
                'resolved' => 'Resolved',
                'cancelled' => 'Cancelled',
            ];
            foreach ($statusTabs as $key => $label):
                $isActive = $filterStatus === $key;
                $count = $statusCounts[$key] ?? 0;
                $href = $key === 'all' ? '?' : '?status=' . urlencode($key);
            ?>
                <a href="<?= $href ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none">
                    <?= $label ?>
                    <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
            <tr class="bg-slate-50/50 border-b border-outline-variant/10">
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Patient</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reporter</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Location</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Concern</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Created</th>
                <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/10">
            <?php foreach ($alerts as $alert):
                $patientName = trim(($alert['first_name'] ?? '') . ' ' . ($alert['last_name'] ?? ''));
            ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-6 py-4"><span class="badge <?= status_badge_class($alert['status']) ?>"><?= e($alert['status']) ?></span></td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-700"><?= e($patientName) ?: 'Unlisted' ?></td>
                    <td class="px-6 py-4">
                        <p class="text-sm font-bold text-slate-600"><?= e($alert['reporter_name']) ?></p>
                        <?php if ($alert['reporter_role']): ?>
                            <p class="text-xs font-bold text-slate-400"><?= e($alert['reporter_role']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e($alert['location']) ?></td>
                    <td class="px-6 py-4">
                        <p class="text-sm font-bold text-slate-800"><?= e($alert['concern']) ?></p>
                        <?php if ($alert['details']): ?>
                            <p class="text-xs font-bold text-slate-400 mt-0.5 max-w-[200px] truncate"><?= e($alert['details']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= e(date('M d, g:i A', strtotime($alert['created_at']))) ?></td>
                    <td class="px-4 py-4 text-right">
                        <?php if ($alert['status'] === 'Pending'): ?>
                            <form method="post" action="update.php" style="display:inline;">
                                <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <input type="hidden" name="status" value="In Progress">
                                <button type="submit" class="btn btn-sm btn-outline" title="Acknowledge">
                                    <span class="material-symbols-outlined text-[14px]">play_arrow</span> Ack
                                </button>
                            </form>
                        <?php elseif ($alert['status'] === 'In Progress'): ?>
                            <form method="post" action="update.php" style="display:inline;">
                                <input type="hidden" name="id" value="<?= (int)$alert['id'] ?>">
                                <input type="hidden" name="status" value="Resolved">
                                <button type="submit" class="btn btn-sm btn-primary" title="Resolve">
                                    <span class="material-symbols-outlined text-[14px]">check</span> Resolve
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$alerts): ?>
                <tr>
                    <td colspan="7">
                        <div class="empty-state">
                            <span class="material-symbols-outlined">notifications_off</span>
                            <p class="empty-state-title">No alerts found</p>
                            <p class="empty-state-text"><?= $filterStatus !== 'all' ? 'No alerts with this status.' : 'No alerts have been submitted yet.' ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
