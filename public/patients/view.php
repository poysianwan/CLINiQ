<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_login();

$id = (int)($_GET['id'] ?? 0);

// ── Fetch patient ───────────────────────────────────────────
$stmt = db()->prepare('SELECT * FROM patients WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$patient = $stmt->fetch();

if (!$patient) {
    render_header('Patient Not Found');
    ?>
    <div class="empty-state" style="min-height: 40vh;">
        <span class="material-symbols-outlined">person_off</span>
        <p class="empty-state-title">Patient not found</p>
        <p class="empty-state-text">The patient record you're looking for doesn't exist.</p>
        <a href="index.php" class="btn btn-primary mt-4 text-decoration-none">Back to Patients</a>
    </div>
    <?php
    render_footer();
    exit;
}

$fullName = trim($patient['first_name'] . ' ' . $patient['last_name']);

// ── Visit history ───────────────────────────────────────────
$visitStmt = db()->prepare("
    SELECT v.*, u.name AS recorded_by_name
    FROM clinic_visits v
    LEFT JOIN users u ON u.id = v.recorded_by
    WHERE v.patient_id = ?
    ORDER BY v.visit_datetime DESC
    LIMIT 50
");
$visitStmt->execute([$id]);
$visits = $visitStmt->fetchAll();

// ── APE records ─────────────────────────────────────────────
$apeStmt = db()->prepare("
    SELECT a.*, u.name AS verified_by_name
    FROM ape_records a
    LEFT JOIN users u ON u.id = a.verified_by
    WHERE a.patient_id = ?
    ORDER BY a.exam_date DESC
");
$apeStmt->execute([$id]);
$apeRecords = $apeStmt->fetchAll();

// ── Referrals ───────────────────────────────────────────────
$refStmt = db()->prepare("SELECT * FROM referrals WHERE patient_id = ? ORDER BY referral_date DESC");
$refStmt->execute([$id]);
$referrals = $refStmt->fetchAll();

render_header($fullName . ' — Patient Profile');
?>

<!-- ═══ Header ═══ -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div class="flex items-center gap-4">
        <div class="avatar w-16 h-16 text-xl <?= avatar_color($fullName) ?>"><?= initials($fullName) ?></div>
        <div>
            <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]"><?= e($fullName) ?></h1>
            <p class="text-sm font-bold text-slate-500 mt-1"><?= e($patient['student_number']) ?> · <?= e($patient['course_section']) ?: 'No section' ?></p>
        </div>
    </div>
    <div class="flex flex-wrap gap-3">
        <a class="btn btn-outline text-decoration-none" href="edit.php?id=<?= $id ?>">
            <span class="material-symbols-outlined text-[18px]">edit</span> Edit
        </a>
        <a class="btn btn-ghost text-decoration-none" href="emergency_profile.php?id=<?= $id ?>">
            <span class="material-symbols-outlined text-[18px]">emergency</span> Emergency Profile
        </a>
        <a class="btn btn-primary text-decoration-none" href="<?= app_url('visits/create.php?patient_id=' . $id) ?>">
            <span class="material-symbols-outlined text-[18px]">add_notes</span> Record Visit
        </a>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1fr_1.5fr] gap-6">
    <!-- ═══ Health Profile Card ═══ -->
    <section class="clinic-card p-6 md:p-8 self-start">
        <div class="flex items-center justify-between mb-6">
            <h2 class="font-headline text-xl font-extrabold text-[#1c2a59]">Health Profile</h2>
            <span class="badge badge-critical">
                <span class="material-symbols-outlined">lock</span> Private
            </span>
        </div>

        <dl class="space-y-5">
            <div>
                <dt class="clinic-label">Birthdate</dt>
                <dd class="text-sm font-bold text-slate-700"><?= $patient['birthdate'] ? e(date('F j, Y', strtotime($patient['birthdate']))) : 'Not specified' ?></dd>
            </div>
            <div>
                <dt class="clinic-label">Sex</dt>
                <dd class="text-sm font-bold text-slate-700"><?= e($patient['sex']) ?: 'Not specified' ?></dd>
            </div>
            <div>
                <dt class="clinic-label">Blood Type</dt>
                <dd class="text-sm font-bold text-slate-700">
                    <?php if ($patient['blood_type']): ?>
                        <span class="px-3 py-1 rounded-full bg-red-50 text-red-700 text-xs font-black"><?= e($patient['blood_type']) ?></span>
                    <?php else: ?>
                        Not specified
                    <?php endif; ?>
                </dd>
            </div>
            <div>
                <dt class="clinic-label">Critical Allergies</dt>
                <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['allergies'])) ?: '<span class="text-slate-400">None recorded</span>' ?></dd>
            </div>
            <div>
                <dt class="clinic-label">Existing Conditions</dt>
                <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['existing_conditions'])) ?: '<span class="text-slate-400">None recorded</span>' ?></dd>
            </div>
            <div>
                <dt class="clinic-label">Emergency Instructions</dt>
                <dd class="text-sm font-bold text-slate-700"><?= nl2br(e($patient['emergency_instructions'])) ?: '<span class="text-slate-400">None recorded</span>' ?></dd>
            </div>
            <div>
                <dt class="clinic-label">Guardian</dt>
                <dd class="text-sm font-bold text-slate-700">
                    <?= e($patient['guardian_name']) ?: 'Not specified' ?>
                    <?php if ($patient['guardian_contact']): ?>
                        <span class="text-slate-400">·</span> <?= e($patient['guardian_contact']) ?>
                    <?php endif; ?>
                </dd>
            </div>
        </dl>
    </section>

    <!-- ═══ Visit History ═══ -->
    <section class="space-y-6">
        <div class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="font-headline text-xl font-extrabold text-[#1c2a59]">Visit History</h2>
                    <p class="text-xs font-bold text-slate-500"><?= count($visits) ?> recorded visit(s)</p>
                </div>
            </div>

            <?php if ($visits): ?>
            <div class="divide-y divide-outline-variant/10">
                <?php foreach ($visits as $visit): ?>
                <div class="p-5 hover:bg-slate-50/50 transition-colors">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl <?= in_array($visit['risk_level'], ['High', 'Critical']) ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-primary' ?> flex items-center justify-center shrink-0 mt-0.5">
                                <span class="material-symbols-outlined text-[20px]">medical_information</span>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800"><?= e($visit['chief_complaint']) ?></p>
                                <?php if ($visit['symptoms']): ?>
                                    <p class="text-xs font-bold text-slate-500 mt-1"><?= e($visit['symptoms']) ?></p>
                                <?php endif; ?>
                                <div class="flex flex-wrap items-center gap-2 mt-2">
                                    <span class="badge <?= risk_badge_class($visit['risk_level']) ?>">
                                        <?= e($visit['risk_level']) ?> · <?= (int)$visit['risk_score'] ?>
                                    </span>
                                    <?php if ($visit['temperature']): ?>
                                        <span class="text-xs font-bold text-slate-400"><?= e($visit['temperature']) ?>°C</span>
                                    <?php endif; ?>
                                    <?php if ($visit['blood_pressure']): ?>
                                        <span class="text-xs font-bold text-slate-400"><?= e($visit['blood_pressure']) ?> mmHg</span>
                                    <?php endif; ?>
                                    <?php if ($visit['pulse_rate']): ?>
                                        <span class="text-xs font-bold text-slate-400"><?= (int)$visit['pulse_rate'] ?> bpm</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($visit['action_taken']): ?>
                                    <p class="text-xs font-bold text-emerald-700 mt-2">
                                        <span class="material-symbols-outlined text-[14px] align-middle">check</span>
                                        <?= e($visit['action_taken']) ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs font-bold text-slate-400"><?= e(date('M d, Y', strtotime($visit['visit_datetime']))) ?></p>
                            <p class="text-[10px] font-bold text-slate-400"><?= e(date('g:i A', strtotime($visit['visit_datetime']))) ?></p>
                            <?php if ($visit['recorded_by_name']): ?>
                                <p class="text-[10px] font-bold text-slate-400 mt-1 italic"><?= e($visit['recorded_by_name']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="empty-state">
                    <span class="material-symbols-outlined">clinical_notes</span>
                    <p class="empty-state-title">No visits recorded</p>
                    <p class="empty-state-text">This patient has no clinic visit history yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- APE Documents -->
        <?php if ($apeRecords): ?>
        <div class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-slate-100">
                <h2 class="font-headline text-lg font-extrabold text-[#1c2a59]">APE Documents</h2>
            </div>
            <div class="divide-y divide-outline-variant/10">
                <?php foreach ($apeRecords as $ape): ?>
                <div class="p-5 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                            <span class="material-symbols-outlined text-[20px]">description</span>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-800">Annual Physical Exam</p>
                            <p class="text-xs font-bold text-slate-400"><?= $ape['exam_date'] ? e(date('M d, Y', strtotime($ape['exam_date']))) : 'No date' ?></p>
                        </div>
                    </div>
                    <span class="badge <?= status_badge_class($ape['verification_status']) ?>"><?= e($ape['verification_status']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Referrals -->
        <?php if ($referrals): ?>
        <div class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-slate-100">
                <h2 class="font-headline text-lg font-extrabold text-[#1c2a59]">Referrals</h2>
            </div>
            <div class="divide-y divide-outline-variant/10">
                <?php foreach ($referrals as $ref): ?>
                <div class="p-5 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-bold text-slate-800"><?= e($ref['referred_to']) ?></p>
                        <p class="text-xs font-bold text-slate-500"><?= e($ref['reason']) ?></p>
                        <p class="text-xs font-bold text-slate-400 mt-1"><?= e(date('M d, Y', strtotime($ref['referral_date']))) ?></p>
                    </div>
                    <span class="badge <?= status_badge_class($ref['status']) ?>"><?= e($ref['status']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php render_footer(); ?>
