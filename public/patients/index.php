<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

// Count total
if ($search !== '') {
    $countStmt = db()->prepare("SELECT COUNT(*) AS total FROM patients WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ?");
    $like = "%{$search}%";
    $countStmt->execute([$like, $like, $like]);
} else {
    $countStmt = db()->query("SELECT COUNT(*) AS total FROM patients");
}
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch page
if ($search !== '') {
    $stmt = db()->prepare("SELECT * FROM patients WHERE first_name LIKE ? OR last_name LIKE ? OR student_number LIKE ? ORDER BY last_name, first_name LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = db()->prepare("SELECT * FROM patients ORDER BY last_name, first_name LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute();
}
$patients = $stmt->fetchAll();

render_header('Patients');
?>

<!-- ═══ Title ═══ -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Patient Records</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Manage student profiles, emergency tags, and staff-only health details.</p>
    </div>
    <a class="btn btn-primary text-decoration-none" href="create.php">
        <span class="material-symbols-outlined text-[20px]">person_add</span>
        Add Patient
    </a>
</div>

<!-- ═══ Patient Registry ═══ -->
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <!-- Header + Search -->
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Patient Registry</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $totalRows ?> registered patient(s). Public QR page is for reporting only; staff profile contains private health data.</p>
            </div>
            <form method="get" class="search-input-wrap" style="max-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or student no..." class="search-input">
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
            <tr class="bg-slate-50/50 border-b border-outline-variant/10">
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Student No.</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Name</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Course/Section</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Guardian Contact</th>
                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-outline-variant/10">
            <?php foreach ($patients as $patient):
                $fullName = trim($patient['last_name'] . ', ' . $patient['first_name']);
                $displayName = trim($patient['first_name'] . ' ' . $patient['last_name']);
            ?>
                <tr class="table-row-clickable" onclick="window.location.href='view.php?id=<?= (int)$patient['id'] ?>'">
                    <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e($patient['student_number']) ?></td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="avatar <?= avatar_color($displayName) ?>"><?= initials($displayName) ?></div>
                            <strong class="text-sm text-slate-800"><?= e($fullName) ?></strong>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e($patient['course_section']) ?></td>
                    <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e($patient['guardian_contact']) ?: '—' ?></td>
                    <td class="px-6 py-4" onclick="event.stopPropagation()">
                        <div class="flex flex-wrap gap-2">
                            <a class="btn btn-sm btn-ghost text-decoration-none" href="view.php?id=<?= (int) $patient['id'] ?>">
                                <span class="material-symbols-outlined text-[14px]">visibility</span> View
                            </a>
                            <a class="btn btn-sm btn-outline text-decoration-none" href="<?= app_url('emergency.php?token=' . $patient['emergency_token']) ?>" target="_blank">
                                <span class="material-symbols-outlined text-[14px]">qr_code_2</span> QR
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$patients): ?>
                <tr>
                    <td colspan="5">
                        <div class="empty-state">
                            <span class="material-symbols-outlined">person_search</span>
                            <p class="empty-state-title"><?= $search ? 'No patients found' : 'No patients yet' ?></p>
                            <p class="empty-state-text"><?= $search ? 'Try a different search term.' : 'Add a patient to get started.' ?></p>
                        </div>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_left</span></span>
        <?php endif; ?>

        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i === $page): ?>
                <span class="page-active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?><?= $search ? '&q=' . urlencode($search) : '' ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?><?= $search ? '&q=' . urlencode($search) : '' ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_right</span></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
