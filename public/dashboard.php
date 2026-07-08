<?php

require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$counts = [
    'patients' => (int)(db()->query('SELECT COUNT(*) AS total FROM patients')->fetch()['total'] ?? 0),
    'pending_alerts' => (int)(db()->query("SELECT COUNT(*) AS total FROM nurse_alerts WHERE status = 'Pending'")->fetch()['total'] ?? 0),
    'visits_today' => (int)(db()->query('SELECT COUNT(*) AS total FROM clinic_visits WHERE DATE(visit_datetime) = CURDATE()')->fetch()['total'] ?? 0),
    'low_stock' => (int)(db()->query('SELECT COUNT(*) AS total FROM inventory_items WHERE quantity <= reorder_level')->fetch()['total'] ?? 0),
];

$apeMetrics = [
    'total' => (int)(db()->query('SELECT COUNT(*) AS total FROM ape_records')->fetch()['total'] ?? 0),
    'digitized' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE document_path IS NOT NULL AND document_path <> ''")->fetch()['total'] ?? 0),
    'hard_copy_pending' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE COALESCE(requirement_status, '') <> 'Pre-Verified'")->fetch()['total'] ?? 0),
    'for_review' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE COALESCE(requirement_status, '') <> 'Pre-Verified'")->fetch()['total'] ?? 0),
    'online_pending' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE requirement_status = 'Pre-Verified' AND (document_path IS NULL OR document_path = '' OR COALESCE(verification_status, 'Pending') IN ('Pending','Needs Correction'))")->fetch()['total'] ?? 0),
    'follow_up' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE follow_up_required = 1 OR workflow_status = 'Follow-up Required' OR clearance_status IN ('For Follow-up','Submitted')")->fetch()['total'] ?? 0),
    'cleared' => (int)(db()->query("SELECT COUNT(*) AS total FROM ape_records WHERE workflow_status = 'Cleared' OR clearance_status = 'Cleared'")->fetch()['total'] ?? 0),
];
$clearanceRate = $apeMetrics['total'] > 0 ? round(($apeMetrics['cleared'] / $apeMetrics['total']) * 100) : 0;

$stepCounts = [];
$stepStmt = db()->query('SELECT workflow_status, COUNT(*) AS cnt FROM ape_records GROUP BY workflow_status');
foreach ($stepStmt->fetchAll() as $row) {
    $stepCounts[$row['workflow_status']] = (int)$row['cnt'];
}

$allApeRecords = db()->query("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section
    FROM ape_records a
    JOIN patients p ON p.id = a.patient_id
    ORDER BY a.updated_at DESC, a.created_at DESC
")->fetchAll();
$dashboardQueues = ape_work_queues();
$recordsByQueue = array_fill_keys(array_keys($dashboardQueues), []);
foreach ($allApeRecords as $record) {
    $recordsByQueue[ape_record_queue($record)][] = $record;
}
$apeQueue = [];
foreach (['document_review', 'digital_submission', 'follow_up'] as $queueKey) {
    foreach ($recordsByQueue[$queueKey] as $record) {
        $record['_queue_key'] = $queueKey;
        $apeQueue[] = $record;
    }
}
$apeQueue = array_slice($apeQueue, 0, 8);

$latestAlerts = db()->query("
    SELECT a.*, p.first_name, p.last_name
    FROM nurse_alerts a
    LEFT JOIN patients p ON p.id = a.patient_id
    WHERE a.status = 'Pending'
    ORDER BY a.created_at DESC
    LIMIT 4
")->fetchAll();

$stockWarnings = db()->query("
    SELECT *
    FROM inventory_items
    WHERE quantity <= reorder_level
    ORDER BY quantity ASC, item_name ASC
    LIMIT 4
")->fetchAll();

render_header('Dashboard');
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">Clinic Dashboard</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]">Today's APE Work</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Main clinic workspace for hard-copy document review, digital record keeping, follow-ups, and completed APE records.</p>
    </div>
    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <?php if ($apeMetrics['follow_up'] > 0): ?>
            <a href="<?= app_url('ape/index.php?queue=follow_up') ?>" class="bg-red-50 border border-red-100 px-4 py-2 rounded-2xl flex items-center gap-3 text-decoration-none">
                <span class="w-8 h-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center material-symbols-outlined text-[18px]">medical_information</span>
                <span>
                    <span class="block text-[9px] font-black text-red-700 uppercase tracking-widest">Follow-up</span>
                    <span class="block text-[11px] font-bold text-red-900"><?= (int)$apeMetrics['follow_up'] ?> student(s) need clearance</span>
                </span>
            </a>
        <?php endif; ?>
        <a class="btn btn-primary text-decoration-none" href="<?= app_url('ape/index.php') ?>">
            <span class="material-symbols-outlined text-[20px]">description</span>
            Open APE Center
        </a>
    </div>
</div>

<section class="clinic-card p-6 md:p-8 bg-white overflow-hidden">
    <div class="flex flex-col xl:flex-row justify-between gap-6">
        <div class="max-w-3xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-fixed border border-outline-variant text-primary text-[10px] font-black uppercase tracking-widest mb-4">
                <span class="material-symbols-outlined text-[14px]">fact_check</span>
                Primary Workflow
            </div>
            <h2 class="font-headline text-2xl md:text-3xl font-extrabold text-[#17261d] leading-tight">Start with the queue that has students waiting.</h2>
            <p class="text-sm font-bold text-slate-500 mt-3 mb-0">The dashboard follows the corrected APE clinic flow: review hard-copy medical documents, wait for student online uploads, archive submissions, manage follow-up requirements, then complete the record.</p>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 xl:min-w-[520px]">
            <a href="<?= app_url('ape/index.php') ?>" class="rounded-2xl border border-slate-100 bg-slate-50 p-4 text-decoration-none">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">APE Records</p>
                <p class="font-headline text-3xl font-extrabold text-slate-800 mb-0"><?= (int)$apeMetrics['total'] ?></p>
            </a>
            <a href="<?= app_url('ape/index.php') ?>" class="rounded-2xl border border-outline-variant bg-primary-fixed p-4 text-decoration-none">
                <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-2">Digitized</p>
                <p class="font-headline text-3xl font-extrabold text-primary mb-0"><?= (int)$apeMetrics['digitized'] ?></p>
            </a>
            <a href="<?= app_url('ape/index.php') ?>" class="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-decoration-none">
                <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest mb-2">To Check</p>
                <p class="font-headline text-3xl font-extrabold text-amber-900 mb-0"><?= (int)$apeMetrics['hard_copy_pending'] ?></p>
            </a>
            <a href="<?= app_url('ape/index.php?queue=completed') ?>" class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 text-decoration-none">
                <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-2">Cleared</p>
                <p class="font-headline text-3xl font-extrabold text-emerald-800 mb-0"><?= (int)$clearanceRate ?>%</p>
            </a>
        </div>
    </div>
</section>

<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6">
    <a href="<?= app_url('ape/index.php?queue=document_review') ?>" class="clinic-card p-6 text-decoration-none group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Hard-Copy Review</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int)$apeMetrics['for_review'] ?></p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all material-symbols-outlined">rate_review</span>
        </div>
        <p class="text-xs font-bold text-slate-400 mt-4 mb-0">Hard-copy requirements waiting for clinic findings review</p>
    </a>

    <a href="<?= app_url('ape/index.php?queue=digital_submission') ?>" class="clinic-card p-6 text-decoration-none group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Online Keeping</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int)$apeMetrics['online_pending'] ?></p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center group-hover:bg-primary group-hover:text-white transition-all material-symbols-outlined">upload_file</span>
        </div>
        <p class="text-xs font-bold text-slate-400 mt-4 mb-0">Checked documents waiting for student upload or clinic archive</p>
    </a>

    <a href="<?= app_url('alerts/index.php') ?>" class="clinic-card p-6 text-decoration-none group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending Alerts</p>
                <p id="pending-alert-count" class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int)$counts['pending_alerts'] ?></p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-all material-symbols-outlined">notification_important</span>
        </div>
        <p class="text-xs font-bold text-slate-400 mt-4 mb-0">Emergency requests still visible to clinic staff</p>
    </a>

    <a href="<?= app_url('visits/index.php') ?>" class="clinic-card p-6 text-decoration-none group">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Visits Today</p>
                <p class="font-headline text-4xl text-slate-800 font-extrabold leading-none mt-2"><?= (int)$counts['visits_today'] ?></p>
            </div>
            <span class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:bg-emerald-600 group-hover:text-white transition-all material-symbols-outlined">clinical_notes</span>
        </div>
        <p class="text-xs font-bold text-slate-400 mt-4 mb-0">Routine clinic visits remain accessible</p>
    </a>
</div>

<section class="clinic-card p-6">
    <div class="flex flex-col lg:flex-row justify-between gap-4 lg:items-center mb-6">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Clinic Work Queues</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Start with the first queue that has students. Each queue points to the next clinic task.</p>
        </div>
        <a class="btn btn-ghost btn-sm text-decoration-none" href="<?= app_url('ape/create.php') ?>">
            <span class="material-symbols-outlined text-[14px]">add</span>
            Add APE Record
        </a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 xl:grid-cols-8 gap-3">
        <?php foreach ($dashboardQueues as $queueKey => $queue): ?>
            <a href="<?= app_url('ape/index.php?queue=' . urlencode($queueKey)) ?>" class="rounded-2xl border border-outline-variant bg-white p-4 text-decoration-none hover:bg-primary-fixed hover:border-primary transition-colors">
                <div class="flex items-center justify-between gap-2">
                    <span class="w-7 h-7 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[15px]"><?= e($queue['icon']) ?></span>
                    <span class="text-lg font-headline font-extrabold text-slate-700"><?= count($recordsByQueue[$queueKey]) ?></span>
                </div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-3 mb-0"><?= e($queue['short_title'] ?? $queue['title']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid grid-cols-1 xl:grid-cols-[1.35fr_0.65fr] gap-6">
    <section class="clinic-card overflow-hidden">
        <div class="p-6 border-b border-slate-100">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#17261d]">APE Action Queue</h2>
                    <p class="text-xs font-bold text-slate-500">Priority students needing hard-copy review, online document keeping, or follow-up clearance.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="search-input-wrap" style="max-width: 240px;">
                        <span class="search-icon material-symbols-outlined">search</span>
                        <input type="text" id="dashboardSearch" placeholder="Search name or ID..." class="search-input">
                    </div>
                    <a href="<?= app_url('ape/index.php') ?>" class="btn btn-primary">
                        <span class="material-symbols-outlined text-[18px]">open_in_new</span>
                        Manage APE
                    </a>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                <tr class="bg-slate-50/50 border-b border-outline-variant/10">
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Student</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Work Queue</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Missing / Waiting For</th>
                    <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Last Updated</th>
                    <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Next Action</th>
                </tr>
                </thead>
                <tbody id="dashboardTableBody" class="divide-y divide-outline-variant/10">
                <?php foreach ($apeQueue as $rec):
                    $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
                    $searchData = $fullName . ' ' . $rec['student_number'] . ' ' . ($rec['document_type'] ?? '');
                    $queueKey = $rec['_queue_key'];
                    $queue = $dashboardQueues[$queueKey];
                    $next = ape_next_action($rec);
                ?>
                    <tr class="hover:bg-slate-50/50 transition-colors" data-search="<?= e($searchData) ?>">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="avatar <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
                                <div>
                                    <strong class="text-sm text-slate-800"><?= e($fullName) ?></strong>
                                    <div class="text-xs font-bold text-slate-400"><?= e($rec['student_number']) ?><?= $rec['course_section'] ? ' - ' . e($rec['course_section']) : '' ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="badge <?= $queueKey === 'follow_up' ? 'badge-high' : 'badge-in-progress' ?>"><?= e($queue['short_title'] ?? $queue['title']) ?></span>
                            <div class="text-xs font-bold text-slate-400 mt-2"><?= e($rec['document_type'] ?: 'APE documents') ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm font-bold text-slate-600 max-w-xs"><?= e(ape_missing_item($rec)) ?></td>
                        <td class="px-6 py-4 text-xs font-bold text-slate-500"><?= e(date('M d, g:i A', strtotime($rec['updated_at'] ?: $rec['created_at']))) ?></td>
                        <td class="px-4 py-4 text-right">
                            <a href="<?= app_url('ape/view.php?id=' . (int)$rec['id']) ?>" class="btn btn-sm btn-primary text-decoration-none">
                                <span class="material-symbols-outlined text-[14px]"><?= e($next['icon']) ?></span>
                                <?= e($next['label']) ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$apeQueue): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <span class="material-symbols-outlined">description</span>
                                <p class="empty-state-title">No APE records yet</p>
                                <p class="empty-state-text">Create an APE record to start tracking freshman progress.</p>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <aside class="space-y-6">
        <section class="clinic-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d]">Required Documents</h2>
                <a href="<?= app_url('ape/index.php?queue=digital_submission') ?>" class="text-xs font-black text-primary text-decoration-none">Online keeping</a>
            </div>
            <div class="space-y-3">
                <?php foreach (['Lab Request Form', 'UHS Consent Form', 'UHS Medical Record', 'UHS Dental Record', 'Referral Form'] as $documentName): ?>
                    <div class="p-4 rounded-2xl bg-slate-50 border border-slate-100">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 rounded-xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined text-[18px]">description</span>
                            <strong class="text-sm text-slate-800"><?= e($documentName) ?></strong>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="clinic-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d]">Medical Purpose</h2>
                <a href="<?= app_url('ape/index.php') ?>" class="text-xs font-black text-primary text-decoration-none">APE records</a>
            </div>
            <?php foreach (['Baseline health data', 'Detect underlying conditions', 'Manage common illnesses', 'Prevent disease spread', 'Determine fitness to study'] as $purpose): ?>
                <div class="flex items-center gap-3 p-3 rounded-2xl bg-primary-fixed border border-outline-variant mb-3">
                    <span class="w-8 h-8 rounded-xl bg-white text-primary flex items-center justify-center material-symbols-outlined text-[18px]">check_circle</span>
                    <strong class="text-sm text-[#17261d]"><?= e($purpose) ?></strong>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="clinic-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d]">Live Alerts</h2>
                <a href="<?= app_url('alerts/index.php') ?>" class="text-xs font-black text-primary text-decoration-none">View all</a>
            </div>
            <div id="latest-alerts" data-alert-feed="<?= app_url('api/alerts.php') ?>">
                <?php foreach ($latestAlerts as $alert): ?>
                    <div class="p-4 rounded-2xl bg-red-50 border border-red-100 mb-3">
                        <div class="flex items-start justify-between gap-3">
                            <strong class="text-sm text-red-900"><?= e($alert['concern']) ?></strong>
                            <span class="badge badge-pending"><?= e($alert['status']) ?></span>
                        </div>
                        <p class="text-xs font-bold text-red-700 mb-0 mt-1"><?= e($alert['location']) ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if (!$latestAlerts): ?>
                    <p class="text-sm font-bold text-slate-500 mb-0">No pending alerts.</p>
                <?php endif; ?>
            </div>
        </section>

        <section class="clinic-card p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-headline text-lg font-extrabold text-[#17261d]">Stock Watch</h2>
                <a href="<?= app_url('inventory/index.php') ?>" class="text-xs font-black text-primary text-decoration-none">Manage</a>
            </div>
            <?php foreach ($stockWarnings as $item): ?>
                <div class="flex items-center gap-3 p-3 rounded-2xl bg-amber-50 border border-amber-100 mb-3">
                    <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center material-symbols-outlined text-[20px]">pill</span>
                    <div class="flex-1 min-w-0">
                        <strong class="block text-sm text-amber-900 truncate"><?= e($item['item_name']) ?></strong>
                        <span class="text-xs font-bold text-amber-700"><?= (int)$item['quantity'] ?> <?= e($item['unit']) ?> left</span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$stockWarnings): ?>
                <p class="text-sm font-bold text-slate-500 mb-0">No low-stock items.</p>
            <?php endif; ?>
        </section>
    </aside>
</div>

<section class="clinic-card p-6">
    <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-4">Quick Actions</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
        <a class="btn btn-primary justify-center text-decoration-none" href="<?= app_url('ape/create.php') ?>">
            <span class="material-symbols-outlined text-[20px]">add_notes</span>
            Add APE
        </a>
        <a class="btn btn-primary justify-center text-decoration-none" href="<?= app_url('ape/index.php') ?>">
            <span class="material-symbols-outlined text-[20px]">fact_check</span>
            Manage APE
        </a>
        <a class="btn btn-ghost justify-center text-decoration-none" href="<?= app_url('patients/create.php') ?>">
            <span class="material-symbols-outlined text-[20px]">person_add</span>
            Add Patient
        </a>
        <a class="btn btn-ghost justify-center text-decoration-none" href="<?= app_url('visits/create.php') ?>">
            <span class="material-symbols-outlined text-[20px]">clinical_notes</span>
            Record Visit
        </a>
        <a class="btn btn-danger justify-center text-decoration-none" href="<?= app_url('alerts/create.php') ?>">
            <span class="material-symbols-outlined text-[20px]">emergency_home</span>
            Submit Alert
        </a>
        <a class="btn btn-ghost justify-center text-decoration-none" href="<?= app_url('reports/index.php') ?>">
            <span class="material-symbols-outlined text-[20px]">analytics</span>
            Reports
        </a>
    </div>
</section>

<script>
    initTableSearch('dashboardSearch', '#dashboardTableBody');
</script>

<?php render_footer(); ?>
