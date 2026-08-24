<?php
/** @var array $settings */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Settings</h1>
    <div class="page-sub">Mail, notification toggles per role, and report automation. No code editing required.</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/settings')) ?>" class="panel animate-fadeup form-panel" style="animation-delay:.05s">
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

  <div class="form-foot">
    <button type="submit" class="btn btn-primary">Save settings</button>
  </div>
</form>
