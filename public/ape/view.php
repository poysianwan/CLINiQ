<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/ApeWorkflow.php';
require_login();
ensure_ape_workflow_schema();

$id = (int)($_GET['id'] ?? 0);

function fetch_ape_record(int $id): ?array
{
    $stmt = db()->prepare("
        SELECT a.*, p.first_name, p.last_name, p.student_number, p.course_section, p.sex, p.birthdate, u.name AS verified_by_name
        FROM ape_records a
        JOIN patients p ON p.id = a.patient_id
        LEFT JOIN users u ON u.id = a.verified_by
        WHERE a.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch();
    return $record ?: null;
}

function upload_ape_file(string $fieldName, string $prefix): ?string
{
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
        return null;
    }

    $uploadDir = dirname(__DIR__, 2) . '/uploads/ape/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    move_uploaded_file($_FILES[$fieldName]['tmp_name'], $uploadDir . $filename);
    return 'uploads/ape/' . $filename;
}

$record = fetch_ape_record($id);

if (!$record) {
    flash_message('error', 'APE record not found.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = current_user()['id'];
    $activityLabel = null;
    $activityNotes = null;

    if ($action === 'mark_requirements_complete') {
        $notes = trim($_POST['clinical_remarks'] ?? '');
        $stmt = db()->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', workflow_status = 'Requirements Checked', follow_up_required = 0, clearance_status = 'Pending', result_status = 'Fit to Proceed', clinical_remarks = ?, missing_items = NULL WHERE id = ?");
        $stmt->execute([$notes ?: null, $id]);
        $activityLabel = 'Completed hard-copy document review';
        $activityNotes = $notes ?: 'No follow-up required';
    } elseif ($action === 'mark_missing_requirements') {
        $missingItems = trim($_POST['missing_items'] ?? 'Follow-up requirement not specified.');
        $notes = trim($_POST['clinical_remarks'] ?? '');
        $stmt = db()->prepare("UPDATE ape_records SET requirement_status = 'Pre-Verified', missing_items = ?, workflow_status = 'Requirements Checked', follow_up_required = 1, clearance_status = 'For Follow-up', result_status = 'With Finding', clinical_remarks = ?, result_notes = ? WHERE id = ?");
        $stmt->execute([$missingItems, $notes ?: null, $notes ?: $missingItems, $id]);
        $activityLabel = 'Recorded finding and follow-up requirement';
        $activityNotes = $missingItems;
    } elseif ($action === 'approve_documents') {
        $documentPath = $record['document_path'];
        $nextWorkflow = (int)($record['follow_up_required'] ?? 0) === 1 ? 'Follow-up Required' : 'Cleared';
        $nextClearance = (int)($record['follow_up_required'] ?? 0) === 1 ? 'For Follow-up' : 'Cleared';
        $stmt = db()->prepare("UPDATE ape_records SET document_path = ?, verification_status = 'Verified', verified_by = ?, workflow_status = ?, clearance_status = ?, missing_items = IF(follow_up_required = 1, missing_items, NULL) WHERE id = ?");
        $stmt->execute([$documentPath, $userId, $nextWorkflow, $nextClearance, $id]);
        $activityLabel = 'Archived online APE documents';
    } elseif ($action === 'request_document_correction') {
        $missingItems = trim($_POST['missing_items'] ?? 'Online document correction required.');
        $stmt = db()->prepare("UPDATE ape_records SET verification_status = 'Needs Correction', requirement_status = 'Pre-Verified', missing_items = ?, workflow_status = 'Submitted', verified_by = ? WHERE id = ?");
        $stmt->execute([$missingItems, $userId, $id]);
        $activityLabel = 'Requested online document correction';
        $activityNotes = $missingItems;
    } elseif ($action === 'keep_follow_up_open') {
        $followUpNotes = trim($_POST['follow_up_notes'] ?? 'Follow-up remains open.');
        $stmt = db()->prepare("UPDATE ape_records SET clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required', clinical_remarks = ? WHERE id = ?");
        $stmt->execute([$followUpNotes, $id]);
        $activityLabel = 'Kept follow-up open';
        $activityNotes = $followUpNotes;
    } elseif ($action === 'approve_clearance') {
        $stmt = db()->prepare("UPDATE ape_records SET clearance_status = 'Cleared', follow_up_required = 0, workflow_status = 'Cleared' WHERE id = ?");
        $stmt->execute([$id]);
        $activityLabel = 'Approved follow-up clearance';
    } elseif ($action === 'return_clearance') {
        $missingItems = trim($_POST['missing_items'] ?? 'Clearance correction required.');
        $stmt = db()->prepare("UPDATE ape_records SET clearance_status = 'For Follow-up', workflow_status = 'Follow-up Required', missing_items = ? WHERE id = ?");
        $stmt->execute([$missingItems, $id]);
        $activityLabel = 'Returned clearance for correction';
        $activityNotes = $missingItems;
    } elseif ($action === 'save_notes') {
        $stmt = db()->prepare("UPDATE ape_records SET clinical_remarks = ?, student_visible_note = ?, extracted_text = ? WHERE id = ?");
        $stmt->execute([
            trim($_POST['clinical_remarks'] ?? '') ?: null,
            trim($_POST['student_visible_note'] ?? '') ?: null,
            trim($_POST['extracted_text'] ?? '') ?: null,
            $id,
        ]);
        $activityLabel = 'Updated APE notes';
    }

    if ($activityLabel) {
        ape_log_activity($id, $userId, $activityLabel, $activityNotes);
        flash_message('success', $activityLabel . '.');
    }

    header('Location: view.php?id=' . $id);
    exit;
}

$record = fetch_ape_record($id);
$fullName = trim($record['first_name'] . ' ' . $record['last_name']);
$queueKey = ape_record_queue($record);
$queue = ape_work_queues()[$queueKey];
$next = ape_next_action($record);
$currentStep = ape_record_step_index($record);
$actionCard = ape_next_action_card($record);

$activityStmt = db()->prepare("
    SELECT l.*, u.name AS user_name
    FROM ape_activity_logs l
    LEFT JOIN users u ON u.id = l.user_id
    WHERE l.ape_record_id = ?
    ORDER BY l.created_at DESC
    LIMIT 20
");
$activityStmt->execute([$id]);
$activities = $activityStmt->fetchAll();

render_header('APE Record - ' . $fullName);
?>

<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <p class="text-[11px] font-black text-primary uppercase tracking-widest mb-2">APE Student Workflow</p>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#17261d]"><?= e($fullName) ?></h1>
        <p class="text-sm font-bold text-slate-500 mt-1"><?= e($record['student_number']) ?><?= $record['course_section'] ? ' - ' . e($record['course_section']) : '' ?></p>
    </div>
    <div class="flex flex-wrap gap-3">
        <a class="btn btn-ghost text-decoration-none" href="<?= app_url('patients/view.php?id=' . (int)$record['patient_id']) ?>">
            <span class="material-symbols-outlined text-[18px]">folder_shared</span> Patient Record
        </a>
        <a class="btn btn-ghost text-decoration-none" href="index.php">
            <span class="material-symbols-outlined text-[18px]">arrow_back</span> Work Queues
        </a>
    </div>
</div>

<section class="clinic-card p-6 md:p-8">
    <div class="flex flex-col xl:flex-row justify-between gap-6">
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-primary-fixed border border-outline-variant text-primary text-[10px] font-black uppercase tracking-widest mb-4">
                <span class="material-symbols-outlined text-[14px]"><?= e($queue['icon']) ?></span>
                <?= e($queue['title']) ?>
            </div>
            <h2 class="font-headline text-2xl md:text-3xl font-extrabold text-[#17261d] mb-2">Next Action: <?= e($next['label']) ?></h2>
            <p class="text-sm font-bold text-slate-500 mb-3"><?= e($actionCard['title']) ?></p>
            <p class="text-sm font-semibold text-slate-500 mb-0 max-w-2xl"><?= e($actionCard['body']) ?></p>
            <div class="flex flex-wrap gap-2 mt-5">
                <span class="badge <?= ape_status_badge_class($record['workflow_status']) ?>"><?= e(ape_record_stage_label($record)) ?></span>
                <span class="badge <?= ape_priority_badge($record)['class'] ?>"><?= e(ape_waiting_label($record)) ?></span>
            </div>
        </div>
        <div class="xl:min-w-[420px]">
            <?php if ($queueKey === 'document_review'): ?>
                <div class="grid grid-cols-1 gap-3">
                    <form method="post" class="grid grid-cols-1 gap-3">
                        <input type="hidden" name="action" value="mark_requirements_complete">
                        <textarea class="clinic-textarea" name="clinical_remarks" rows="3" placeholder="Clinic notes after checking hard-copy documents..."><?= e($record['clinical_remarks']) ?></textarea>
                        <button class="btn btn-primary w-full"><span class="material-symbols-outlined text-[18px]">check_circle</span> No Concern - Continue to Online Submission</button>
                    </form>
                    <form method="post" class="grid grid-cols-1 gap-3">
                        <input type="hidden" name="action" value="mark_missing_requirements">
                        <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Required follow-up: treatment, clearance, repeat lab, referral, or missing requirement..."><?= e($record['missing_items']) ?></textarea>
                        <textarea class="clinic-textarea" name="clinical_remarks" rows="3" placeholder="Finding or health concern notes..."><?= e($record['clinical_remarks']) ?></textarea>
                        <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);"><span class="material-symbols-outlined text-[18px]">medical_information</span> Record Finding / Follow-up</button>
                    </form>
                </div>
            <?php elseif ($queueKey === 'digital_submission'): ?>
                <div class="grid grid-cols-1 gap-3">
                    <?php if (empty($record['document_path'])): ?>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-amber-700 mt-0.5">hourglass_empty</span>
                                <div>
                                    <p class="text-sm font-black text-amber-900 mb-1">Waiting for Student Upload</p>
                                    <p class="text-xs font-bold text-amber-800 mb-3">The student must upload the checked documents from the student portal. Clinic staff can review and archive the submission after it appears here.</p>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach (['Lab Request Form', 'UHS Consent Form', 'UHS Medical Record', 'UHS Dental Record', 'Referral Form'] as $type): ?>
                                            <span class="badge badge-pending"><?= e($type) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="action" value="approve_documents">
                            <button class="btn btn-primary w-full"><span class="material-symbols-outlined text-[18px]">inventory_2</span> Archive Documents</button>
                        </form>
                        <form method="post" class="grid grid-cols-1 gap-3">
                            <input type="hidden" name="action" value="request_document_correction">
                            <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="What should the student correct or resubmit online?"><?= e($record['missing_items']) ?></textarea>
                            <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);"><span class="material-symbols-outlined text-[18px]">edit_note</span> Request Resubmission</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php elseif ($queueKey === 'follow_up'): ?>
                <div class="grid grid-cols-1 gap-3">
                    <form method="post" class="grid grid-cols-1 gap-3">
                        <input type="hidden" name="action" value="keep_follow_up_open">
                        <textarea class="clinic-textarea" name="follow_up_notes" rows="3" placeholder="Treatment or follow-up notes..."><?= e($record['clinical_remarks']) ?></textarea>
                        <button class="btn btn-ghost w-full"><span class="material-symbols-outlined text-[18px]">history</span> Keep Follow-up Open</button>
                    </form>
                    <?php if (!empty($record['clearance_document_path']) || ($record['clearance_status'] ?? '') === 'Submitted'): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="approve_clearance">
                            <button class="btn btn-primary w-full"><span class="material-symbols-outlined text-[18px]">verified</span> Approve Clearance</button>
                        </form>
                        <form method="post" class="grid grid-cols-1 gap-3">
                            <input type="hidden" name="action" value="return_clearance">
                            <textarea class="clinic-textarea" name="missing_items" rows="3" placeholder="Reason for returning clearance..."><?= e($record['missing_items']) ?></textarea>
                            <button class="btn btn-outline w-full" style="color:#b45309;border-color:rgba(180,83,9,0.2);"><span class="material-symbols-outlined text-[18px]">assignment_return</span> Return for Correction</button>
                        </form>
                    <?php else: ?>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-amber-700 mt-0.5">hourglass_empty</span>
                                <div>
                                    <p class="text-sm font-black text-amber-900 mb-1">Waiting for Student Follow-up Upload</p>
                                    <p class="text-xs font-bold text-amber-800 mb-0">The student must upload treatment proof, medical clearance, or the required follow-up document from the student portal before clinic approval.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <a class="btn btn-ghost w-full text-decoration-none" href="<?= app_url('ape/index.php?queue=completed') ?>">
                    <span class="material-symbols-outlined text-[18px]">task_alt</span> Completed Record
                </a>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="clinic-card p-6">
    <div class="flex flex-col lg:flex-row justify-between gap-4 lg:items-center mb-6">
        <div>
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-1">APE Progress</h2>
            <p class="text-xs font-bold text-slate-500 mb-0">Progress updates as clinic staff completes each next action.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="badge <?= ape_status_badge_class($record['workflow_status']) ?>"><?= e($record['workflow_status']) ?></span>
            <span class="badge <?= ape_status_badge_class($record['clearance_status']) ?>"><?= e($record['clearance_status']) ?></span>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <?php foreach (ape_workflow_steps() as $i => $step):
            $done = $i < $currentStep || ($queueKey === 'completed' && $i <= $currentStep);
            $active = $i === $currentStep && $queueKey !== 'completed';
        ?>
            <div class="rounded-2xl border <?= $active ? 'border-primary bg-primary-fixed' : ($done ? 'border-emerald-100 bg-emerald-50/60' : 'border-outline-variant bg-slate-50/60') ?> p-4">
                <span class="w-7 h-7 rounded-xl <?= $done ? 'bg-emerald-600 text-white' : ($active ? 'bg-primary text-white' : 'bg-white text-slate-400') ?> flex items-center justify-center text-xs font-black">
                    <?= $done ? '<span class="material-symbols-outlined text-[15px]">check</span>' : $i + 1 ?>
                </span>
                <p class="text-[10px] font-black uppercase tracking-widest <?= $active ? 'text-primary' : 'text-slate-400' ?> mt-3 mb-0"><?= e($step) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>


<div class="grid grid-cols-1 xl:grid-cols-[1.1fr_0.9fr] gap-6 items-start">
    <!-- Main Content: Chronological Timeline -->
    <div class="space-y-6">
        <!-- Phase 1: Hard-Copy Document Review (Always Visible) -->
        <section class="clinic-card p-6">
            <div class="flex items-center gap-3 mb-5 border-b border-slate-100 pb-4">
                <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center font-black text-xs">1</span>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Phase 1: Hard-Copy Review</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <p class="clinic-label">Status</p>
                    <span class="badge <?= ape_status_badge_class($record['requirement_status']) ?>"><?= e($record['requirement_status']) ?></span>
                </div>
                <div>
                    <p class="clinic-label">Finding / Missing Items</p>
                    <p class="text-sm font-bold text-slate-700 mb-0"><?= e(ape_missing_item($record)) ?></p>
                </div>
                <div class="sm:col-span-2">
                    <p class="clinic-label">Clinic Notes (Physical Check)</p>
                    <p class="text-sm font-bold text-slate-700 mb-0 whitespace-pre-wrap"><?= e($record['clinical_remarks'] ?: 'No physical check notes recorded.') ?></p>
                </div>
            </div>
        </section>

        <!-- Phase 2: Online Document Keeping -->
        <?php if ($record['requirement_status'] !== 'Not Checked'): ?>
        <section class="clinic-card p-6">
            <div class="flex items-center gap-3 mb-5 border-b border-slate-100 pb-4">
                <span class="w-8 h-8 rounded-full bg-slate-100 text-slate-500 flex items-center justify-center font-black text-xs">2</span>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Phase 2: Digital Archive</h2>
            </div>
            <div class="space-y-4">
                <div class="flex items-center justify-between gap-3 p-4 rounded-2xl bg-slate-50 border border-slate-100">
                    <div>
                        <strong class="text-sm text-slate-800"><?= e($record['document_type']) ?></strong>
                        <p class="text-xs font-bold text-slate-400 mb-0">
                            <?= $record['document_path'] ? 'Submitted online for clinic archive' : 'Waiting for online submission' ?>
                        </p>
                    </div>
                    <?php if ($record['document_path']): ?>
                        <a href="<?= app_url($record['document_path']) ?>" target="_blank" class="btn btn-sm btn-outline text-decoration-none">View File</a>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="clinic-label">Verification Status</p>
                    <span class="badge <?= ape_status_badge_class($record['verification_status']) ?>"><?= e($record['verification_status']) ?></span>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Phase 3: Clinic Findings & Follow-up (Conditional) -->
        <?php if ((int)$record['follow_up_required'] === 1 || $record['result_status'] !== 'Pending'): ?>
        <section class="clinic-card p-6 border-l-4 border-amber-400">
            <div class="flex items-center gap-3 mb-5 border-b border-slate-100 pb-4">
                <span class="w-8 h-8 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center font-black text-xs">3</span>
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Phase 3: Medical Clearance</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-5">
                <div>
                    <p class="clinic-label">Finding Status</p>
                    <span class="badge <?= ape_status_badge_class($record['result_status']) ?>"><?= e($record['result_status']) ?></span>
                </div>
                <div>
                    <p class="clinic-label">Clearance Status</p>
                    <span class="badge <?= ape_status_badge_class($record['clearance_status']) ?>"><?= e($record['clearance_status']) ?></span>
                </div>
                <div class="sm:col-span-2">
                    <p class="clinic-label">Follow-up Notes / Instructions</p>
                    <p class="text-sm font-bold text-slate-700 mb-0 whitespace-pre-wrap"><?= e($record['result_notes'] ?: 'No specific follow-up instructions.') ?></p>
                </div>
            </div>
            <div class="flex items-center justify-between gap-3 p-4 rounded-2xl bg-amber-50/50 border border-amber-100">
                <div>
                    <strong class="text-sm text-amber-900">Follow-up Clearance File</strong>
                    <p class="text-xs font-bold text-amber-700/70 mb-0">
                        <?= $record['clearance_document_path'] ? 'Submitted for approval' : 'No clearance file submitted yet' ?>
                    </p>
                </div>
                <?php if ($record['clearance_document_path']): ?>
                    <a href="<?= app_url($record['clearance_document_path']) ?>" target="_blank" class="btn btn-sm btn-outline text-decoration-none border-amber-200 text-amber-700 hover:bg-amber-100">View Clearance</a>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <!-- Sidebar Content -->
    <div class="space-y-6">
        <section class="clinic-card p-6">
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-4">Student Information</h2>
            <dl class="space-y-4">
                <div>
                    <dt class="clinic-label">Student Name</dt>
                    <dd class="text-sm font-bold text-slate-700"><?= e($fullName) ?></dd>
                </div>
                <div>
                    <dt class="clinic-label">Student ID</dt>
                    <dd class="text-sm font-bold text-slate-700"><?= e($record['student_number']) ?></dd>
                </div>
                <div>
                    <dt class="clinic-label">Course / Section</dt>
                    <dd class="text-sm font-bold text-slate-700"><?= e($record['course_section'] ?? 'Not set') ?></dd>
                </div>
                <div>
                    <dt class="clinic-label">Purpose</dt>
                    <dd class="text-sm font-bold text-slate-700">Freshman APE medical record</dd>
                </div>
            </dl>
        </section>

        <section class="clinic-card p-6">
            <h2 class="font-headline text-xl font-extrabold text-[#17261d] mb-4">Additional Notes</h2>
            <form method="post" class="space-y-4">
                <input type="hidden" name="action" value="save_notes">
                <div>
                    <label class="clinic-label">Student-Visible Note</label>
                    <textarea class="clinic-textarea" name="student_visible_note" rows="3" placeholder="Notes the student can see..."><?= e($record['student_visible_note']) ?></textarea>
                </div>
                <div>
                    <label class="clinic-label">Extracted Text / Manual Entry</label>
                    <textarea class="clinic-textarea" name="extracted_text" rows="3" placeholder="OCR text or manual data entry..."><?= e($record['extracted_text']) ?></textarea>
                </div>
                <button class="btn btn-primary w-full"><span class="material-symbols-outlined text-[18px]">save</span> Save Notes</button>
            </form>
        </section>

        <section class="clinic-card overflow-hidden">
            <div class="p-6 border-b border-slate-100">
                <h2 class="font-headline text-xl font-extrabold text-[#17261d] m-0">Activity History</h2>
            </div>
            <div class="divide-y divide-slate-100 max-h-80 overflow-y-auto">
                <?php foreach ($activities as $activity): ?>
                    <div class="p-4">
                        <strong class="text-sm text-slate-800 block"><?= e($activity['action_label']) ?></strong>
                        <?php if ($activity['notes']): ?>
                            <p class="text-xs font-bold text-slate-500 mt-1 mb-1"><?= e($activity['notes']) ?></p>
                        <?php endif; ?>
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 mb-0">
                            <?= e($activity['user_name'] ?? 'System') ?> &bull; <?= e(date('M d, Y g:i A', strtotime($activity['created_at']))) ?>
                        </p>
                    </div>
                <?php endforeach; ?>
                <?php if (!$activities): ?>
                    <div class="p-6 text-center">
                        <span class="material-symbols-outlined text-slate-300 text-3xl mb-2">history</span>
                        <p class="text-xs font-bold text-slate-500 m-0">No activity yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php render_footer(); ?>
