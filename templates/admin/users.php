<?php
/** @var array $users */
/** @var array $roles */
/** @var array $departments */
$me = $user;
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Users</h1>
    <div class="page-sub">Roles: Admin (full access) · Department Manager (own department, modify) · Viewer (own department, read-only).</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/users')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <div class="upload-row">
    <label class="field"><span>Username *</span><input class="input" type="text" name="user[username]" required></label>
    <label class="field"><span>Full name</span><input class="input" type="text" name="user[full_name]"></label>
    <label class="field"><span>Email</span><input class="input" type="email" name="user[email]"></label>
    <label class="field"><span>Password * (min 8)</span><input class="input" type="password" name="user[password]" required minlength="8"></label>
    <label class="field"><span>Role</span>
      <select class="input" name="user[role_id]">
        <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e(ucfirst(str_replace('_', ' ', $r['name']))) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="field"><span>Department</span>
      <select class="input" name="user[department_id]">
        <option value="">— None (all) —</option>
        <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="upload-actions"><button class="btn btn-primary">Create user</button></div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Username</th><th>Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td><strong><?= e($u['username']) ?></strong><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="badge">(you)</span>' : '' ?></td>
            <td><?= e($u['full_name']) ?></td>
            <td><?= e($u['email']) ?></td>
            <td><span class="action-chip"><?= e(ucfirst(str_replace('_', ' ', (string) $u['role_name']))) ?></span></td>
            <td><?= e($u['department_name'] ?? '—') ?></td>
            <td>
              <form method="post" action="<?= e(url('/admin/users/' . (int) $u['id'])) ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="user[username]" value="<?= e($u['username']) ?>">
                <input type="hidden" name="user[full_name]" value="<?= e($u['full_name']) ?>">
                <input type="hidden" name="user[email]" value="<?= e($u['email']) ?>">
                <input type="hidden" name="user[role_id]" value="<?= (int) $u['role_id'] ?>">
                <input type="hidden" name="user[department_id]" value="<?= (int) ($u['department_id'] ?? 0) ?>">
                <input type="hidden" name="user[is_active]" value="<?= $u['is_active'] ? 1 : 0 ?>">
                <button class="btn btn-xs <?= $u['is_active'] ? 'btn-ghost' : 'btn-primary' ?>"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
            <td class="nowrap">
              <form method="post" action="<?= e(url('/admin/users/' . (int) $u['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Delete user <?= e($u['username']) ?>? Their assets are kept but unassigned.')"
                    <?= (int) $u['id'] === (int) $me['id'] ? 'style="display:none"' : '' ?>>
                <?= csrf_field() ?>
                <button class="btn btn-xs btn-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<div class="panel animate-fadeup" style="margin-top:16px;animation-delay:.15s">
  <div class="panel-head"><h2>Edit user</h2></div>
  <div class="panel-body">
    <form class="filter-grid user-edit" data-users='<?= e(json_encode(array_map(static fn ($u) => [
        'id' => (int) $u['id'], 'username' => $u['username'], 'full_name' => $u['full_name'],
        'email' => $u['email'], 'role_id' => (int) $u['role_id'],
        'department_id' => (int) ($u['department_id'] ?? 0), 'is_active' => $u['is_active'] ? 1 : 0,
    ], $users))) ?>' data-departments='<?= e(json_encode(array_map(static fn ($d) => ['id' => (int) $d['id'], 'name' => $d['name']], $departments))) ?>' data-roles='<?= e(json_encode(array_map(static fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name']], $roles))) ?>'
        method="post" action="#">
      <?= csrf_field() ?>
      <label class="field"><span>User</span>
        <select class="input" name="edit_id"><option value="">Choose user…</option></select>
      </label>
      <label class="field"><span>Full name</span><input class="input" name="user[full_name]" type="text"></label>
      <label class="field"><span>Email</span><input class="input" name="user[email]" type="email"></label>
      <label class="field"><span>New password (optional)</span><input class="input" name="user[password]" type="password" minlength="8" placeholder="Leave blank to keep"></label>
      <label class="field"><span>Role</span>
        <select class="input" name="user[role_id]">
          <?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= e(ucfirst(str_replace('_', ' ', $r['name']))) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span>Department</span>
        <select class="input" name="user[department_id]">
          <option value="">— None —</option>
          <?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <div class="filter-actions"><button class="btn btn-primary">Save user</button></div>
    </form>
  </div>
</div>
