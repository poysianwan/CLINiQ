<?php

require_once __DIR__ . '/../../app/helpers/view.php';
require_once __DIR__ . '/../../app/services/RiskClassifier.php';
require_login();

$patients = db()->query('SELECT id, student_number, first_name, last_name FROM patients ORDER BY last_name, first_name')->fetchAll();

// Pre-select patient if coming from patient profile
$preselectedPatientId = (int)($_GET['patient_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $risk = classify_patient_risk($_POST);
    $stmt = db()->prepare(
        'INSERT INTO clinic_visits (patient_id, visit_datetime, chief_complaint, symptoms, temperature, blood_pressure, pulse_rate, risk_level, risk_score, action_taken, recorded_by)
         VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $_POST['patient_id'],
        trim($_POST['chief_complaint'] ?? ''),
        trim($_POST['symptoms'] ?? ''),
        $_POST['temperature'] ?: null,
        trim($_POST['blood_pressure'] ?? ''),
        $_POST['pulse_rate'] ?: null,
        $risk['level'],
        $risk['score'],
        trim($_POST['action_taken'] ?? ''),
        current_user()['id'],
    ]);

    flash_message('success', 'Visit recorded — Risk: ' . $risk['level'] . ' (Score: ' . $risk['score'] . ')');
    header('Location: index.php');
    exit;
}

render_header('Record Visit');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Record Clinic Visit</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Add triage notes and apply rule-based risk classification.</p>
    </div>
    <a class="btn btn-ghost text-decoration-none" href="index.php">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span> Back to Visits
    </a>
</div>

<div class="grid grid-cols-1 xl:grid-cols-[1.4fr_0.6fr] gap-6">
    <!-- Visit Form -->
    <form class="clinic-card p-6 md:p-8" method="post" id="visitForm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label class="clinic-label">Patient</label>
                <select class="clinic-select" name="patient_id" id="patientSelect" required>
                    <option value="">Select patient</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?= (int) $patient['id'] ?>" <?= $preselectedPatientId === (int)$patient['id'] ? 'selected' : '' ?>><?= e($patient['last_name'] . ', ' . $patient['first_name'] . ' - ' . $patient['student_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="clinic-label">Chief Complaint</label>
                <input class="clinic-input" name="chief_complaint" id="chiefComplaint" required>
            </div>
            <div class="md:col-span-2">
                <label class="clinic-label">Symptoms</label>
                <textarea class="clinic-textarea" name="symptoms" id="symptomsInput" rows="3" placeholder="Example: fever, chest pain, difficulty breathing"></textarea>
            </div>
            <div>
                <label class="clinic-label">Temperature (°C)</label>
                <input class="clinic-input" name="temperature" id="tempInput" type="number" step="0.1" placeholder="e.g. 37.5">
            </div>
            <div>
                <label class="clinic-label">Blood Pressure</label>
                <input class="clinic-input" name="blood_pressure" placeholder="e.g. 120/80">
            </div>
            <div>
                <label class="clinic-label">Pulse Rate (bpm)</label>
                <input class="clinic-input" name="pulse_rate" id="pulseInput" type="number" placeholder="e.g. 72">
            </div>
            <div class="md:col-span-2">
                <label class="clinic-label">Action Taken</label>
                <textarea class="clinic-textarea" name="action_taken" rows="3" placeholder="Treatment, medication given, advice..."></textarea>
            </div>
        </div>
        <div class="mt-6 flex flex-wrap gap-3">
            <button class="btn btn-primary">
                <span class="material-symbols-outlined text-[18px]">save</span> Save Visit
            </button>
            <a class="btn btn-ghost text-decoration-none" href="index.php">Cancel</a>
        </div>
    </form>

    <!-- Live Risk Preview -->
    <aside class="self-start">
        <div class="clinic-card p-6">
            <h3 class="font-headline text-lg font-extrabold text-[#1c2a59] mb-4">Risk Preview</h3>
            <p class="text-xs font-bold text-slate-400 mb-4">Estimated risk level based on entered vitals and symptoms. Final classification is computed server-side on save.</p>

            <div id="riskPreview" class="text-center py-6">
                <div id="riskBadgePreview" class="badge badge-low text-lg px-6 py-3 mx-auto mb-3">
                    <span class="material-symbols-outlined">check_circle</span>
                    <span id="riskLevelText">Low</span>
                </div>
                <p class="text-sm font-bold text-slate-500">Score: <span id="riskScoreText" class="font-extrabold text-slate-800">0</span></p>
                <div id="riskReasons" class="mt-3 space-y-1"></div>
            </div>
        </div>
    </aside>
</div>

<script>
// Client-side risk preview (mirrors RiskClassifier.php logic)
function updateRiskPreview() {
    const temp = parseFloat(document.getElementById('tempInput')?.value) || 0;
    const pulse = parseInt(document.getElementById('pulseInput')?.value) || 0;
    const symptoms = (document.getElementById('symptomsInput')?.value || '').toLowerCase();

    let score = 0;
    const reasons = [];

    if (temp >= 39.0) { score += 3; reasons.push('High fever'); }
    else if (temp >= 37.8) { score += 1; reasons.push('Fever'); }

    if (pulse > 0 && (pulse > 120 || pulse < 50)) { score += 2; reasons.push('Abnormal pulse rate'); }

    const critical = ['chest pain', 'difficulty breathing', 'fainting', 'seizure', 'severe bleeding'];
    critical.forEach(s => {
        if (symptoms.includes(s)) { score += 4; reasons.push(s.charAt(0).toUpperCase() + s.slice(1)); }
    });

    let level, badgeClass;
    if (score >= 7) { level = 'Critical'; badgeClass = 'badge-critical'; }
    else if (score >= 4) { level = 'High'; badgeClass = 'badge-high'; }
    else if (score >= 2) { level = 'Moderate'; badgeClass = 'badge-moderate'; }
    else { level = 'Low'; badgeClass = 'badge-low'; }

    const badge = document.getElementById('riskBadgePreview');
    badge.className = `badge ${badgeClass} text-lg px-6 py-3 mx-auto mb-3`;
    document.getElementById('riskLevelText').textContent = level;
    document.getElementById('riskScoreText').textContent = score;

    const reasonsEl = document.getElementById('riskReasons');
    reasonsEl.innerHTML = reasons.map(r => `<p class="text-xs font-bold text-slate-500">• ${escapeHtml(r)}</p>`).join('');
}

document.getElementById('tempInput')?.addEventListener('input', updateRiskPreview);
document.getElementById('pulseInput')?.addEventListener('input', updateRiskPreview);
document.getElementById('symptomsInput')?.addEventListener('input', updateRiskPreview);
</script>

<?php render_footer(); ?>
