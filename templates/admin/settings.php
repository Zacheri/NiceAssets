<?php
/** @var array $settings */
/** @var ?array $logoPhoto */
/** @var array $logoPhotos */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Settings</h1>
    <div class="page-sub">Mail, notification toggles per role, and report automation. No code editing required.</div>
  </div>
</div>

<form method="post" name="settings-form" action="<?= e(url('/admin/settings')) ?>" class="panel animate-fadeup form-panel" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <h2 class="section-title">Email delivery</h2>
  <div class="form-grid">
    <label class="field"><span>From address</span>
      <input class="input" type="email" name="settings[mail_from]" value="<?= e($settings['mail_from']) ?>">
    </label>
    <label class="field"><span>SMTP host (blank = use system mail)</span>
      <input class="input" type="text" name="settings[smtp_host]" value="<?= e($settings['smtp_host']) ?>" placeholder="e.g. mail.example.com">
    </label>
    <label class="field"><span>SMTP port</span>
      <input class="input" type="number" name="settings[smtp_port]" value="<?= (int) $settings['smtp_port'] ?>">
    </label>
    <label class="field"><span>SMTP username</span>
      <input class="input" type="text" name="settings[smtp_user]" value="<?= e($settings['smtp_user']) ?>">
    </label>
    <label class="field"><span>SMTP password</span>
      <input class="input" type="password" name="settings[smtp_pass]" value="<?= e($settings['smtp_pass']) ?>" autocomplete="new-password">
    </label>
    <label class="field"><span>SMTP encryption</span>
      <select class="input" name="settings[smtp_secure]">
        <option value="tls" <?= $settings['smtp_secure'] === 'tls' ? 'selected' : '' ?>>TLS (port 587)</option>
        <option value="none" <?= $settings['smtp_secure'] === 'none' ? 'selected' : '' ?>>None (port 25/110)</option>
      </select>
    </label>
  </div>

  <h2 class="section-title">Email notifications by role</h2>
  <div class="toggle-list">
    <label class="toggle-row"><span><strong>Admin</strong> — receives email alerts (warranty, low stock, depreciation)</span>
      <input type="checkbox" class="switch" name="settings[email_role_admin]" value="1" <?= $settings['email_role_admin'] === '1' ? 'checked' : '' ?>></label>
    <label class="toggle-row"><span><strong>Department Manager</strong> — receives email alerts</span>
      <input type="checkbox" class="switch" name="settings[email_role_department_manager]" value="1" <?= $settings['email_role_department_manager'] === '1' ? 'checked' : '' ?>></label>
    <label class="toggle-row"><span><strong>Viewer</strong> — receives email alerts</span>
      <input type="checkbox" class="switch" name="settings[email_role_viewer]" value="1" <?= $settings['email_role_viewer'] === '1' ? 'checked' : '' ?>></label>
  </div>
  <div class="table-note">Dashboard alerts are always visible to everyone. Email is sent once per day per item, only for roles enabled above.</div>

  <h2 class="section-title">Automation</h2>
  <div class="form-grid">
    <div class="field field-check">
      <label><input type="checkbox" name="settings[weekly_report_enabled]" value="1" <?= $settings['weekly_report_enabled'] === '1' ? 'checked' : '' ?>> Weekly report job (Saturdays)</label>
      <span></span>
    </div>
    <label class="field"><span>Multi-asset threshold (assets of same model per user)</span>
      <input class="input" type="number" min="2" name="settings[multi_asset_threshold]" value="<?= (int) $settings['multi_asset_threshold'] ?>">
    </label>
  </div>

  <h2 class="section-title">Branding</h2>
  <div class="branding-row">
    <div class="pp-current pp-current-square <?= $logoPhoto !== null ? 'is-set' : '' ?>" id="logo-current">
      <span class="pp-current-letter">N</span>
      <img src="<?= $logoPhoto !== null ? e(url('/uploads/' . rawurlencode((string) $logoPhoto['filename']))) : e(asset_url('img/placeholder.svg')) ?>" alt=""
           onerror="this.src='<?= e(asset_url('img/placeholder.svg')) ?>'">
    </div>
    <div class="branding-actions">
      <span class="pp-current-name"><?= $logoPhoto !== null ? e($logoPhoto['original_name']) : 'No logo set — the “N” mark is shown.' ?></span>
      <div class="pp-current-btns">
        <button class="btn btn-sm" type="button" data-pp-open="pp-logo">Choose logo</button>
        <?php if ($logoPhoto !== null): ?>
          <button class="btn btn-sm btn-ghost" type="button" data-pp-clear="logo_photo_id" data-pp-preview="logo-current">Remove logo</button>
        <?php endif; ?>
      </div>
      <span class="page-sub">Shown in the sidebar and on the sign-in page. Choose from the asset photo gallery.</span>
    </div>
  </div>
  <input type="hidden" name="logo_photo_id" value="<?= (int) ($logoPhoto['id'] ?? 0) ?>" data-pp-hidden>

  <h2 class="section-title">Theme</h2>
  <div class="form-grid">
    <label class="field"><span>Default theme (applies until a user picks their own)</span>
      <select class="input" name="settings[theme_default_preset]">
        <?php foreach (\App\Themes::PRESETS as $name => $preset): ?>
          <option value="<?= e($name) ?>" <?= $settings['theme_default_preset'] === $name ? 'selected' : '' ?>><?= e(ucfirst($name)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </div>
  <div class="toggle-list">
    <?php foreach (\App\Themes::PRESETS as $name => $preset): ?>
      <label class="toggle-row"><span><strong><?= e(ucfirst($name)) ?></strong> — available in the topbar theme menu</span>
        <input type="checkbox" class="switch" name="settings[theme_available_<?= e($name) ?>]" value="1" <?= in_array($name, $settings['theme_available'], true) ? 'checked' : '' ?>></label>
    <?php endforeach; ?>
  </div>
  <div class="table-note">Users pick their own theme from the topbar menu and can fine-tune any color token as a personal override. The default applies to everyone else, including the sign-in page.</div>

  <div class="form-foot">
    <button type="submit" class="btn btn-primary">Save settings</button>
  </div>
</form>

<?= \App\Core\View::partial('photo_picker_modal', [
    'slug' => 'logo',
    'title' => 'Choose a logo',
    'photos' => $logoPhotos,
    'mode' => 'single',
    'name' => 'logo_photo_id',
    'selectedIds' => $logoPhoto !== null ? [(int) $logoPhoto['id']] : [],
    'confirm' => 'submit-form',
    'targetForm' => 'settings-form',
    'buttonLabel' => 'Set logo',
    'preview' => 'logo-current',
    'emptyLink' => '/photos',
]) ?>
