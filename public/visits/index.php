<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

// ── Search & pagination ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$filterRisk = $_GET['risk'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.student_number LIKE ? OR v.chief_complaint LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like, $like, $like, $like]);
}

if ($filterRisk !== 'all') {
    $where[] = "v.risk_level = ?";
    $params[] = $filterRisk;
}

$whereSQL = implode(' AND ', $where);

$countStmt = db()->prepare("SELECT COUNT(*) AS total FROM clinic_visits v JOIN patients p ON p.id = v.patient_id WHERE {$whereSQL}");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetch()['total'];
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = db()->prepare("
    SELECT v.*, p.first_name, p.last_name, p.student_number, u.name AS recorded_by_name
    FROM clinic_visits v
    JOIN patients p ON p.id = v.patient_id
    LEFT JOIN users u ON u.id = v.recorded_by
    WHERE {$whereSQL}
    ORDER BY v.visit_datetime DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$visits = $stmt->fetchAll();

// Risk level counts
$riskCounts = ['all' => $totalRows];
$riskCountQuery = db()->prepare("SELECT risk_level, COUNT(*) AS cnt FROM clinic_visits v JOIN patients p ON p.id = v.patient_id WHERE 1=1 " . ($search !== '' ? "AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.student_number LIKE ? OR v.chief_complaint LIKE ?)" : "") . " GROUP BY risk_level");
$riskCountQuery->execute($search !== '' ? ["%{$search}%", "%{$search}%", "%{$search}%", "%{$search}%"] : []);
foreach ($riskCountQuery->fetchAll() as $rc) {
    $riskCounts[strtolower($rc['risk_level'])] = (int)$rc['cnt'];
}

$visitColumns = [
    ['headerName' => 'Date/Time', 'field' => 'dateTimeHtml', 'cellRenderer' => 'html', 'width' => 160],
    ['headerName' => 'Patient', 'field' => 'patientHtml', 'cellRenderer' => 'html', 'minWidth' => 230],
    ['headerName' => 'Complaint', 'field' => 'complaint', 'minWidth' => 220],
    ['headerName' => 'Risk', 'field' => 'riskHtml', 'cellRenderer' => 'html', 'width' => 150],
    ['headerName' => 'Recorded By', 'field' => 'recordedBy', 'minWidth' => 170],
    ['headerName' => 'Action Taken', 'field' => 'actionTaken', 'minWidth' => 220],
    ['headerName' => 'Open', 'field' => 'actionsHtml', 'cellRenderer' => 'html', 'sortable' => false, 'filter' => false, 'width' => 120],
];
$visitRows = [];
foreach ($visits as $visit) {
    $fullName = trim($visit['first_name'] . ' ' . $visit['last_name']);
    $visitRows[] = [
        'dateTimeHtml' => '<p class="text-sm font-bold text-slate-700 mb-0">' . e(date('M d, Y', strtotime($visit['visit_datetime']))) . '</p><p class="text-xs font-bold text-slate-400 mb-0">' . e(date('g:i A', strtotime($visit['visit_datetime']))) . '</p>',
        'patientHtml' => '<div class="flex items-center gap-3"><div class="avatar ' . e(avatar_color($fullName)) . '">' . e(initials($fullName)) . '</div><div><strong class="text-sm text-slate-800">' . e($fullName) . '</strong><div class="text-xs font-bold text-slate-400">' . e($visit['student_number']) . '</div></div></div>',
        'complaint' => $visit['chief_complaint'],
        'riskHtml' => '<span class="badge ' . e(risk_badge_class($visit['risk_level'])) . '">' . e($visit['risk_level']) . ' · ' . (int)$visit['risk_score'] . '</span>',
        'recordedBy' => $visit['recorded_by_name'] ?? 'System',
        'actionTaken' => $visit['action_taken'] ?: '-',
        'actionsHtml' => '<a href="' . e(app_url('patients/view.php?id=' . (int)$visit['patient_id'])) . '" class="btn btn-sm btn-ghost text-decoration-none"><span class="material-symbols-outlined text-[14px]">visibility</span> View</a>',
    ];
}

render_header('Clinic Visits');
?>

<!-- ═══ Visits Table ═══ -->
<section class="bg-white rounded-[2rem] border border-outline-variant/20 shadow-sm overflow-hidden">
    <!-- Toolbar -->
    <div class="p-6 border-b border-slate-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
            <div>
                <h2 class="font-headline text-xl font-extrabold text-[#1c2a59] mb-1">Clinic Logbook</h2>
                <p class="text-xs font-bold text-slate-500 mb-0"><?= $totalRows ?> visit record(s)</p>
            </div>
            <form method="get" class="search-input-wrap" style="max-width: 280px;">
                <span class="search-icon material-symbols-outlined">search</span>
                <input id="visitsGridSearch" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, ID, or complaint..." class="search-input">
                <?php if ($filterRisk !== 'all'): ?><input type="hidden" name="risk" value="<?= e($filterRisk) ?>"><?php endif; ?>
            </form>
        </div>

        <!-- Risk Filter Tabs -->
        <div class="flex items-center gap-2 mt-4 border-t border-slate-100 pt-4 overflow-x-auto scrollbar-hide">
            <?php
            $riskTabs = [
                'all' => ['label' => 'All Records', 'color' => 'bg-blue-100 text-primary'],
                'low' => ['label' => 'Low', 'color' => 'bg-emerald-50 text-emerald-600'],
                'moderate' => ['label' => 'Moderate', 'color' => 'bg-amber-50 text-amber-600'],
                'high' => ['label' => 'High', 'color' => 'bg-rose-50 text-rose-600'],
                'critical' => ['label' => 'Critical', 'color' => 'bg-red-50 text-red-600'],
            ];
            foreach ($riskTabs as $key => $tab):
                $isActive = $filterRisk === $key;
                $count = $riskCounts[$key] ?? 0;
                $href = '?' . http_build_query(array_filter(['q' => $search, 'risk' => $key === 'all' ? null : $key]));
            ?>
                <a href="<?= $href ?>" class="status-tab <?= $isActive ? 'active' : '' ?> text-decoration-none">
                    <?= $tab['label'] ?>
                    <span class="ml-1.5 px-2 py-0.5 rounded-full <?= $isActive ? 'bg-blue-100 text-primary' : 'bg-slate-100 text-slate-500' ?> text-[10px]"><?= $count ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php render_ag_grid('visitsGrid', $visitColumns, $visitRows, [
        'searchInput' => 'visitsGridSearch',
        'emptyTitle' => $search || $filterRisk !== 'all' ? 'No matching visits' : 'No clinic visits yet',
        'emptyText' => $search ? 'Try a different search or filter.' : 'Record a clinic visit to get started.',
    ]); ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <?php
        $paginationParams = array_filter(['q' => $search, 'risk' => $filterRisk !== 'all' ? $filterRisk : null]);
        ?>
        <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page - 1])) ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_left</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_left</span></span>
        <?php endif; ?>

        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <?php if ($i === $page): ?>
                <span class="page-active"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $i])) ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($paginationParams, ['page' => $page + 1])) ?>">
                <span class="material-symbols-outlined text-[18px]">chevron_right</span>
            </a>
        <?php else: ?>
            <span class="page-disabled"><span class="material-symbols-outlined text-[18px]">chevron_right</span></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>
