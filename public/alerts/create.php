<?php

require_once __DIR__ . '/../../app/helpers/view.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = db()->prepare(
        'INSERT INTO nurse_alerts (reporter_name, reporter_role, location, concern, details) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($_POST['reporter_name'] ?? ''),
        trim($_POST['reporter_role'] ?? ''),
        trim($_POST['location'] ?? ''),
        trim($_POST['concern'] ?? ''),
        trim($_POST['details'] ?? ''),
    ]);

    flash_message('success', 'Alert submitted! The clinic has been notified.');
    header('Location: index.php');
    exit;
}

render_header('Submit Alert');
?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="font-headline text-3xl md:text-4xl font-extrabold text-[#1c2a59]">Submit Emergency Alert</h1>
        <p class="text-sm font-bold text-slate-500 mt-1">Send an urgent concern to the clinic dashboard.</p>
    </div>
    <a class="btn btn-ghost text-decoration-none" href="index.php">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span> Back
    </a>
</div>

<form class="clinic-card p-6 md:p-8" method="post">
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        <div>
            <label class="clinic-label">Reporter Name</label>
            <input class="clinic-input" name="reporter_name" required placeholder="Your full name">
        </div>
        <div>
            <label class="clinic-label">Reporter Role</label>
            <input class="clinic-input" name="reporter_role" placeholder="Student, Teacher, Staff">
        </div>
        <div>
            <label class="clinic-label">Location</label>
            <input class="clinic-input" name="location" required placeholder="Building, room, or area">
        </div>
        <div>
            <label class="clinic-label">Concern</label>
            <input class="clinic-input" name="concern" required placeholder="Brief description of the emergency">
        </div>
        <div class="md:col-span-2">
            <label class="clinic-label">Details</label>
            <textarea class="clinic-textarea" name="details" rows="4" placeholder="Additional details about the situation..."></textarea>
        </div>
    </div>
    <div class="mt-6">
        <button class="btn btn-danger">
            <span class="material-symbols-outlined text-[18px]">emergency_home</span> Send Alert
        </button>
    </div>
</form>
<?php render_footer(); ?>
