<?php
/** @var array $departments */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Departments</h1>
    <div class="page-sub">Organizational units used for assignment and role scoping.</div>
  </div>
</div>

<form method="post" action="<?= e(url('/admin/departments')) ?>" class="panel upload-panel animate-fadeup" style="animation-delay:.05s">
  <?= csrf_field() ?>
  <div class="upload-row">
    <label class="field field-grow"><span>Department name *</span><input class="input" type="text" name="department[name]" required></label>
    <label class="field field-grow"><span>Description</span><input class="input" type="text" name="department[description]"></label>
    <div class="upload-actions"><button class="btn btn-primary">Add</button></div>
  </div>
</form>

<section class="panel animate-fadeup" style="animation-delay:.1s">
  <div class="panel-body">
    <table class="data">
      <thead><tr><th>Name</th><th>Description</th><th>Assets</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($departments as $d): ?>
          <tr>
            <td><strong><?= e($d['name']) ?></strong></td>
            <td><?= e($d['description'] ?? '') ?></td>
            <td><?= (int) $d['asset_count'] ?></td>
            <td>
              <form method="post" action="<?= e(url('/admin/departments/' . (int) $d['id'] . '/delete')) ?>"
                    onsubmit="return confirm('Delete department <?= e($d['name']) ?>?')">
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
