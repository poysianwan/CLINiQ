<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$activeQueue = $_GET['queue'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$queues = ape_work_queues();

$where = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.student_number LIKE ? OR p.course_section LIKE ? OR a.document_type LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term, $term);
}

$stmt = db()->prepare("
    SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section, u.name AS verified_by_name
    FROM ape_records a
    JOIN patients p ON p.id = a.patient_id
    LEFT JOIN users u ON u.id = a.verified_by
    WHERE {$where}
    ORDER BY a.updated_at DESC, a.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$allRecords = $stmt->fetchAll();

$recordsByQueue = array_fill_keys(array_keys($queues), []);
foreach ($allRecords as $record) {
    $recordsByQueue[ape_record_queue($record)][] = $record;
}

$visibleQueues = $activeQueue === 'all'
    ? array_keys($queues)
    : (isset($queues[$activeQueue]) ? [$activeQueue] : array_keys($queues));

$needsAction = 0;
foreach (['document_review', 'digital_submission', 'follow_up'] as $key) {
    $needsAction += count($recordsByQueue[$key]);
}

$metrics = [
    'total' => count($allRecords),
    'clinic_action' => $needsAction,
    'digitized' => count($recordsByQueue['digital_submission']) + count($recordsByQueue['follow_up']) + count($recordsByQueue['completed']),
    'follow_up' => count($recordsByQueue['follow_up']),
    'completed' => count($recordsByQueue['completed']),
];
$clearanceRate = $metrics['total'] > 0 ? round(($metrics['completed'] / $metrics['total']) * 100) : 0;

render_header('APE Work Queues');
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">Clinic APE Center</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]">APE Work Queues</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Review hard-copy medical documents, archive checked files online, and track follow-up until cleared.</p>
    </div>
    <div class="flex flex-wrap gap-3">
        <a class="btn btn-ghost text-decoration-none" href="<?= app_url('dashboard.php') ?>">
            <span class="material-symbols-outlined text-[18px]">dashboard</span>
            Dashboard
        </a>
        <a class="btn btn-primary text-decoration-none" href="create.php">
            <span class="material-symbols-outlined text-[20px]">add_notes</span>
            Add APE Record
        </a>
    </div>
</div>

<section class="clinic-card p-6 md:p-8">
    <div class="flex flex-col xl:flex-row justify-between gap-6">
        <div class="max-w-3xl">
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-fixed border border-outline-variant text-primary text-[10px] font-black uppercase tracking-widest mb-4">
                <span class="material-symbols-outlined text-[14px]">task_alt</span>
                Guided APE Flow
            </div>
            <h2 class="font-headline text-2xl md:text-3xl font-extrabold text-[#17261d] leading-tight">Move students from medical document review to completed APE records.</h2>
            <p class="text-sm font-bold text-slate-500 mt-3 mb-0">The clinic records findings during hard-copy review, waits for the checked documents to be submitted online, then clears students or manages follow-up requirements.</p>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 xl:min-w-[520px]">
            <div class="rounded-2xl bg-primary-fixed border border-outline-variant p-4">
                <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-1">Needs Action</p>
                <p class="font-headline text-3xl font-extrabold text-[#17261d] mb-0"><?= (int)$metrics['clinic_action'] ?></p>
            </div>
            <div class="rounded-2xl bg-white border border-outline-variant p-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Digitized</p>
                <p class="font-headline text-3xl font-extrabold text-[#17261d] mb-0"><?= (int)$metrics['digitized'] ?></p>
            </div>
            <div class="rounded-2xl bg-amber-50 border border-amber-100 p-4">
                <p class="text-[10px] font-black text-amber-700 uppercase tracking-widest mb-1">Follow-up</p>
                <p class="font-headline text-3xl font-extrabold text-amber-900 mb-0"><?= (int)$metrics['follow_up'] ?></p>
            </div>
            <div class="rounded-2xl bg-emerald-50 border border-emerald-100 p-4">
                <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-1">Cleared</p>
                <p class="font-headline text-3xl font-extrabold text-emerald-800 mb-0"><?= (int)$clearanceRate ?>%</p>
            </div>
        </div>
    </div>
</section>

<section class="clinic-card p-6">
    <div class="flex flex-col lg:flex-row justify-between gap-4 lg:items-center mb-5">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">Work Queue Map</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Click a queue to focus the page. Each student shows one next clinic action.</p>
        </div>
        <form class="search-input-wrap max-w-sm" method="get">
            <?php if ($activeQueue !== 'all'): ?>
                <input type="hidden" name="queue" value="<?= e($activeQueue) ?>">
            <?php endif; ?>
            <span class="search-icon material-symbols-outlined">search</span>
            <input class="search-input" name="q" value="<?= e($search) ?>" placeholder="Search name, ID, course, document...">
        </form>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <a href="?<?= $search !== '' ? 'q=' . urlencode($search) : '' ?>" class="rounded-2xl border <?= $activeQueue === 'all' ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none transition-colors">
            <div class="flex items-center justify-between gap-2">
                <span class="w-8 h-8 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[16px]">view_list</span>
                <strong class="font-headline text-xl text-[#17261d]"><?= count($allRecords) ?></strong>
            </div>
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mt-3 mb-0">All Queues</p>
        </a>
        <?php foreach ($queues as $key => $queue): ?>
            <a href="?queue=<?= urlencode($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" class="rounded-2xl border <?= $activeQueue === $key ? 'border-primary bg-primary-fixed' : 'border-outline-variant bg-white' ?> p-4 text-decoration-none hover:bg-primary-fixed transition-colors">
                <div class="flex items-center justify-between gap-2">
                    <span class="w-8 h-8 rounded-xl bg-white text-primary border border-outline-variant flex items-center justify-center material-symbols-outlined text-[16px]"><?= e($queue['icon']) ?></span>
                    <strong class="font-headline text-xl text-[#17261d]"><?= count($recordsByQueue[$key]) ?></strong>
                </div>
                <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mt-3 mb-0"><?= e($queue['short_title'] ?? $queue['title']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<div class="grid grid-cols-1 gap-6">
    <?php foreach ($visibleQueues as $queueKey):
        $queue = $queues[$queueKey];
        $records = $recordsByQueue[$queueKey];
        $limit = $activeQueue === 'all' ? 6 : 200;
        $shownRecords = array_slice($records, 0, $limit);
    ?>
        <section class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-outline-variant">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div class="flex items-start gap-4">
                        <span class="w-11 h-11 rounded-2xl bg-primary-fixed text-primary flex items-center justify-center material-symbols-outlined"><?= e($queue['icon']) ?></span>
                        <div>
                            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1"><?= e($queue['title']) ?></h2>
                            <p class="text-xs font-bold text-slate-500 mb-0"><?= e($queue['description']) ?></p>
                        </div>
                    </div>
                    <span class="badge <?= $queueKey === 'completed' ? 'badge-completed' : ($queueKey === 'follow_up' ? 'badge-high' : 'badge-in-progress') ?>">
                        <?= count($records) ?> record(s)
                    </span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                    <tr class="bg-slate-50/50 border-b border-outline-variant/40">
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Priority</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Student</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Program</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Waiting</th>
                        <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Next Action</th>
                        <th class="px-4 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-outline-variant/40">
                    <?php foreach ($shownRecords as $rec):
                        $fullName = trim($rec['first_name'] . ' ' . $rec['last_name']);
                        $next = ape_next_action($rec);
                        $priority = ape_priority_badge($rec);
                    ?>
                        <tr class="hover:bg-primary-fixed/40 transition-colors">
                            <td class="px-6 py-4">
                                <span class="badge <?= e($priority['class']) ?>"><?= e($priority['label']) ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="avatar <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
                                    <div>
                                        <strong class="text-sm text-slate-800"><?= e($fullName) ?></strong>
                                        <div class="text-xs font-bold text-slate-400"><?= e($rec['student_number']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm font-bold text-slate-700 mb-1"><?= e($rec['course_section'] ?: 'No course set') ?></p>
                                <p class="text-xs font-bold text-slate-400 mb-0"><?= e($rec['document_type'] ?: 'APE documents') ?></p>
                            </td>
                            <td class="px-6 py-4 text-sm font-bold text-slate-600"><?= e(ape_waiting_label($rec)) ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-[18px]"><?= e($next['icon']) ?></span>
                                    <div>
                                        <strong class="block text-sm text-slate-800"><?= e($next['label']) ?></strong>
                                        <span class="block text-xs font-bold text-slate-400"><?= e(ape_missing_item($rec)) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-right">
                                <a href="view.php?id=<?= (int)$rec['id'] ?>" class="btn <?= $queueKey === 'completed' ? 'btn-ghost' : 'btn-primary' ?> btn-sm text-decoration-none">
                                    <span class="material-symbols-outlined text-[14px]"><?= e($next['icon']) ?></span>
                                    <?= e($next['label']) ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$shownRecords): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <span class="material-symbols-outlined"><?= e($queue['icon']) ?></span>
                                    <p class="empty-state-title">No students here</p>
                                    <p class="empty-state-text">This queue is clear for now.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($activeQueue === 'all' && count($records) > $limit): ?>
                <div class="px-6 py-4 border-t border-outline-variant bg-slate-50/40 text-right">
                    <a class="btn btn-sm btn-ghost text-decoration-none" href="?queue=<?= urlencode($queueKey) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">View all <?= count($records) ?></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<?php render_footer(); ?>
