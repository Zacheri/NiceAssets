<?php
/** @var ?array $person */
/** @var array $departments */
/** @var array $portraitPhotos */
$isEdit = $person !== null;
$hasPortrait = !empty($person['portrait_filename']);
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

<form method="post" name="person-form" action="<?= e(url($isEdit ? '/admin/persons/' . (int) $person['id'] : '/admin/persons')) ?>" class="panel form-panel animate-fadeup">
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

  <div class="photo-picker-block">
    <div class="photo-picker-head">
      <h3>Portrait</h3>
      <span class="page-sub">Profile photo shown on the person's card and detail page. Portraits are uploaded in the Portraits gallery.</span>
    </div>
    <div class="pp-current-row">
      <div class="pp-current <?= $hasPortrait ? 'is-set' : '' ?>" id="portrait-current">
        <span class="pp-current-letter"><?= e(strtoupper(substr((string) ($person['full_name'] ?? 'P'), 0, 1))) ?></span>
        <img src="<?= $hasPortrait ? e(url('/uploads/' . rawurlencode((string) $person['portrait_filename']))) : e(asset_url('img/placeholder.svg')) ?>" alt=""
             onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
      </div>
      <div class="pp-current-meta">
        <span class="pp-current-name"><?= $hasPortrait ? e($person['portrait_name'] ?? '') : 'No portrait set' ?></span>
        <div class="pp-current-btns">
          <button class="btn btn-sm" type="button" data-pp-open="pp-person-portrait">Choose portrait</button>
          <?php if (!empty($person['portrait_photo_id'])): ?>
            <button class="btn btn-sm btn-ghost" type="button" data-pp-clear="person[portrait_photo_id]" data-pp-preview="portrait-current">Remove portrait</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <input type="hidden" name="person[portrait_photo_id]" value="<?= (int) ($person['portrait_photo_id'] ?? 0) ?>" data-pp-hidden>
  </div>
  <div class="form-foot">
    <button class="btn btn-primary"><?= $isEdit ? 'Save changes' : 'Create person' ?></button>
    <a class="btn btn-ghost" href="<?= e(url('/admin/persons')) ?>">Cancel</a>
  </div>
</form>

<?= \App\Core\View::partial('photo_picker_modal', [
    'slug' => 'person-portrait',
    'title' => 'Choose a portrait',
    'photos' => $portraitPhotos,
    'mode' => 'single',
    'name' => 'person[portrait_photo_id]',
    'selectedIds' => !empty($person['portrait_photo_id']) ? [(int) $person['portrait_photo_id']] : [],
    'confirm' => 'submit-form',
    'targetForm' => 'person-form',
    'buttonLabel' => 'Set portrait',
    'preview' => 'portrait-current',
    'emptyLink' => '/photos/portraits',
]) ?>
