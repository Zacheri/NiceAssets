<?php
/** @var ?array $person */
/** @var array $departments */
$isEdit = $person !== null;
$v = static function (string $k) use ($person): string {
    return (string) ($person[$k] ?? '');
};
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title"><?= e($title) ?></h1>
    <div class="page-sub">Employee record used for asset check-outs.</div>
  </div>
</div>

<form method="post" action="<?= e(url($isEdit ? '/admin/persons/' . (int) $person['id'] : '/admin/persons')) ?>" class="panel form-panel animate-fadeup">
  <?= csrf_field() ?>
  <div class="form-grid">
    <label class="field"><span>Full name *</span>
      <input class="input" type="text" name="person[full_name]" value="<?= e($v('full_name')) ?>" required>
    </label>
    <label class="field"><span>Job title</span>
      <input class="input" type="text" name="person[job_title]" value="<?= e($v('job_title')) ?>">
    </label>
    <label class="field"><span>Work email</span>
      <input class="input" type="email" name="person[work_email]" value="<?= e($v('work_email')) ?>">
    </label>
    <label class="field"><span>Personal email</span>
      <input class="input" type="email" name="person[personal_email]" value="<?= e($v('personal_email')) ?>">
    </label>
    <label class="field"><span>Phone</span>
      <input class="input" type="text" name="person[phone]" value="<?= e($v('phone')) ?>">
    </label>
    <label class="field"><span>Department</span>
      <select class="input" name="person[department_id]">
        <option value="">— None —</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= (int) $d['id'] ?>" <?= (int) ($person['department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="field span-2"><span>Address</span>
      <input class="input" type="text" name="person[address]" value="<?= e($v('address')) ?>">
    </label>
    <label class="field span-2"><span>Notes</span>
      <textarea class="input" name="person[notes]" rows="3"><?= e($v('notes')) ?></textarea>
    </label>
    <label class="field switch-field">
      <input class="switch" type="checkbox" name="person[is_terminated]" value="1" <?= !empty($person['is_terminated']) ? 'checked' : '' ?>>
      <span>Terminated</span>
    </label>
  </div>
  <div class="form-foot">
    <button class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create person' ?></button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/persons')) ?>">Cancel</a>
  </div>
</form>
