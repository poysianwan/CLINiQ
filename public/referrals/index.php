<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$filterStatus = $_GET['status'] ?? 'all';

$where = '1=1';
$params = [];
if ($filterStatus !== 'all') {
    $where = 'r.status = ?';
    $params[] = $filterStatus;
}

$stmt = db()->prepare("
    SELECT r.*, p.first_name, p.last_name, p.student_number
    FROM referrals r
    JOIN patients p ON p.id = r.patient_id
    WHERE {$where}
    ORDER BY r.created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$referrals = $stmt->fetchAll();

$statusCounts = ['all' => 0];
$countQuery = db()->query("SELECT status, COUNT(*) AS cnt FROM referrals GROUP BY status");
foreach ($countQuery->fetchAll() as $sc) {
    $statusCounts[strtolower($sc['status'])] = (int)$sc['cnt'];
    $statusCounts['all'] += (int)$sc['cnt'];
}

render_header('Referrals');
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Referrals</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Track patient referrals to external facilities and specialists.</p>
    </div>
    <a class="btn btn-primary text-decoration-none" href="create.php">
        <span class="material-symbols-outlined text-[20px]">send</span>
        New Referral
    </a>
</div>

<section class="clinic-card overflow-hidden">
    <div class="p-6 border-b border-slate-100">
        <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Referral Records</h2>
        <p class="text-xs font-bold text-slate-500 mb-0"><?= $statusCounts['all'] ?> referral(s)</p>

        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $tabs = ['all' => 'All', 'pending' => 'Pending', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
            foreach ($tabs as $key => $label):
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
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Patient</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Referred To</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reason</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/10">
            <?php foreach ($referrals as $ref):
                $fullName = trim($ref['first_name'] . ' ' . $ref['last_name']);
            ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="avatar <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
                            <div>
                                <strong class="text-sm text-slate-800"><?= e($fullName) ?></strong>
                                <div class="text-xs font-bold text-slate-400"><?= e($ref['student_number']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-700"><?= e($ref['referred_to']) ?></td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-600 max-w-[200px] truncate"><?= e($ref['reason']) ?></td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e(date('M d, Y', strtotime($ref['referral_date']))) ?></td>
                    <td class="px-6 py-4"><span class="badge <?= status_badge_class($ref['status']) ?>"><?= e($ref['status']) ?></span></td>
                    <td class="px-4 py-4 text-right">
                        <?php if ($ref['status'] === 'Pending'): ?>
                            <form method="post" action="update.php" style="display:inline;">
                                <input type="hidden" name="id" value="<?= (int)$ref['id'] ?>">
                                <input type="hidden" name="status" value="Completed">
                                <button class="btn btn-sm btn-primary" title="Mark Completed">
                                    <span class="material-symbols-outlined text-[14px]">check</span> Complete
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$referrals): ?>
                <tr>
                    <td colspan="6">
                        <div class="empty-state">
                            <span class="material-symbols-outlined">send</span>
                            <p class="empty-state-title">No referrals</p>
                            <p class="empty-state-text">Create a referral record to track external patient transfers.</p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_footer(); ?>
