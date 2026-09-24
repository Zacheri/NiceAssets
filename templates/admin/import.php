<?php
/** @var string $step */
?>
<div class="page-head animate-fadeup">
  <div>
    <h1 class="page-title">Import Assets</h1>
    <div class="page-sub">
      <?php if ($step === 'upload'): ?>
        Upload an AssetTiger CSV export, check the column mapping, and import.
      <?php elseif ($step === 'preview'): ?>
        Review the mapping and the preview, then import.
      <?php else: ?>
        Import finished.
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="import-steps animate-fadeup" style="animation-delay:.03s">
  <span class="import-step <?= $step === 'upload' ? 'is-active' : 'is-done' ?>">1 · Upload</span>
  <span class="import-step <?= $step === 'preview' ? 'is-active' : ($step === 'report' ? 'is-done' : '') ?>">2 · Preview</span>
  <span class="import-step <?= $step === 'report' ? 'is-active' : '' ?>">3 · Report</span>
</div>

<?php if ($step === 'upload'): ?>
  <form method="post" action="<?= e(url('/admin/import/analyze')) ?>" enctype="multipart/form-data" class="panel animate-fadeup form-panel" style="animation-delay:.05s">
    <?= csrf_field() ?>
    <div class="upload-row">
      <label class="field field-grow"><span>CSV file (max <?= (int) \App\Services\CsvImport::MAX_FILE_MB ?> MB)</span>
        <input class="input" type="file" name="csv" accept=".csv" required>
      </label>
      <button type="submit" class="btn btn-primary">Analyze file</button>
    </div>
    <div class="panel-body">
      <h2 class="section-title">Options</h2>
      <div class="toggle-list">
        <label class="toggle-row"><span>Download asset photos from the export (default: off)</span>
          <input type="checkbox" class="switch" name="opt_photos" value="1"></label>
        <label class="toggle-row"><span>Create missing persons</span>
          <input type="checkbox" class="switch" name="opt_create_persons" value="1" checked></label>
        <label class="toggle-row"><span>AI assist (classify ambiguous values with the local LLM)</span>
          <input type="checkbox" class="switch" name="opt_ai_assist" value="1"></label>
      </div>
      <?php if (empty($llmReady)): ?>
        <div class="table-note">LLM server is not running — AI assist will be skipped.</div>
      <?php endif; ?>
    </div>
  </form>

<?php elseif ($step === 'preview'): ?>
  <form method="post" action="<?= e(url('/admin/import/analyze')) ?>" class="panel animate-fadeup form-panel" style="animation-delay:.05s">
    <?= csrf_field() ?>
    <input type="hidden" name="file_path" value="<?= e($file) ?>">

    <div class="panel-body">
      <h2 class="section-title">Options</h2>
      <div class="toggle-list">
        <label class="toggle-row"><span>Download asset photos from the export</span>
          <input type="checkbox" class="switch" name="opt_photos" value="1" <?= !empty($options['photos']) ? 'checked' : '' ?>></label>
        <label class="toggle-row"><span>Create missing persons</span>
          <input type="checkbox" class="switch" name="opt_create_persons" value="1" <?= !empty($options['create_persons']) ? 'checked' : '' ?>></label>
        <label class="toggle-row"><span>AI assist (classify ambiguous values with the local LLM)</span>
          <input type="checkbox" class="switch" name="opt_ai_assist" value="1" <?= !empty($options['ai_assist']) ? 'checked' : '' ?>></label>
      </div>

      <h2 class="section-title">Column mapping — <?= e($file) ?></h2>
      <div class="table-wrap import-scroll">
        <table class="data table-compact">
          <thead><tr><th>CSV column</th><th style="width:230px">Import as</th></tr></thead>
          <tbody>
            <?php foreach ($header as $col): ?>
              <tr>
                <td><?= e($col) ?></td>
                <td>
                  <select class="input" name="mapping[<?= e($col) ?>]">
                    <?php foreach ($targets as $t): ?>
                      <option value="<?= e($t) ?>" <?= ($mapping[$col] ?? 'ignore') === $t ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $t))) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="cards-row" style="margin-top:18px">
        <div class="stat-card accent-blue"><div class="stat-num"><?= (int) $preview['summary']['to_create'] ?></div><div class="stat-label">To create</div></div>
        <div class="stat-card accent-slate"><div class="stat-num"><?= (int) $preview['summary']['duplicate_skip'] ?></div><div class="stat-label">Duplicate tags skipped</div></div>
        <div class="stat-card accent-purple"><div class="stat-num"><?= (int) $preview['summary']['persons_to_create'] ?></div><div class="stat-label">Persons to create</div></div>
        <div class="stat-card accent-green"><div class="stat-num"><?= (int) $preview['summary']['photos_to_download'] ?></div><div class="stat-label">Photos to download</div></div>
        <div class="stat-card accent-amber"><div class="stat-num"><?= (int) $preview['summary']['issues'] ?></div><div class="stat-label">Rows with issues</div></div>
      </div>

      <?php if (!empty($llm['ai_unavailable'])): ?>
        <div class="import-ai-note"><strong>AI assist unavailable.</strong> <?= e($llm['ai_error'] ?? 'Unknown error.') ?> The deterministic rules are still applied.</div>
      <?php endif; ?>

      <?php if (!empty($llm['departments']) || !empty($llm['persons']) || !empty($llm['brands'])): ?>
        <h2 class="section-title">AI classifications — adjust before importing</h2>
        <?php if (!empty($llm['departments'])): ?>
          <div class="panel-inline-title">Departments — real department vs status note</div>
          <div class="table-wrap import-scroll">
            <table class="data table-compact">
              <thead><tr><th>Value</th><th>Rows</th><th style="width:230px">Classification</th></tr></thead>
              <tbody>
                <?php foreach ($llm['departments'] as $value => $class): ?>
                  <tr>
                    <td><?= e($value) ?></td>
                    <td class="nowrap"><?= (int) ($distinct['department'][$value] ?? 0) ?></td>
                    <td>
                      <select class="input import-override" name="llm_override[departments][<?= e($value) ?>]">
                        <option value="real-dept" <?= $class === 'real-dept' ? 'selected' : '' ?>>Real department</option>
                        <option value="status-note" <?= $class === 'status-note' ? 'selected' : '' ?>>Status note</option>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (!empty($llm['persons'])): ?>
          <div class="panel-inline-title">Persons — person vs non-person</div>
          <div class="table-wrap import-scroll">
            <table class="data table-compact">
              <thead><tr><th>Value</th><th>Rows</th><th style="width:230px">Classification</th></tr></thead>
              <tbody>
                <?php foreach ($llm['persons'] as $value => $class): ?>
                  <tr>
                    <td><?= e($value) ?></td>
                    <td class="nowrap"><?= (int) ($distinct['person'][$value] ?? 0) ?></td>
                    <td>
                      <select class="input import-override" name="llm_override[persons][<?= e($value) ?>]">
                        <option value="person" <?= $class === 'person' ? 'selected' : '' ?>>Person</option>
                        <option value="non-person" <?= $class === 'non-person' ? 'selected' : '' ?>>Non-person</option>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (!empty($llm['brands'])): ?>
          <div class="panel-inline-title">Brands — canonical spelling</div>
          <div class="table-wrap import-scroll">
            <table class="data table-compact">
              <thead><tr><th>Value</th><th>Rows</th><th style="width:230px">Canonical</th></tr></thead>
              <tbody>
                <?php foreach ($llm['brands'] as $value => $class): ?>
                  <tr>
                    <td><?= e($value) ?></td>
                    <td class="nowrap"><?= (int) ($distinct['brand'][$value] ?? 0) ?></td>
                    <td><input class="input import-override" type="text" name="llm_override[brands][<?= e($value) ?>]" value="<?= e($class) ?>"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <h2 class="section-title">Rows with issues (<?= (int) $preview['summary']['issues'] ?>)</h2>
      <?php if ($preview['issues'] === []): ?>
        <div class="empty">No issues found — every row looks clean.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data table-compact">
            <thead><tr><th>Line</th><th>Tag</th><th>Notes</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($preview['issues'], 0, 200) as $issue): ?>
                <tr>
                  <td class="nowrap"><?= (int) $issue['line'] ?></td>
                  <td><?= e($issue['tag']) ?></td>
                  <td><?= e(implode('; ', $issue['notes'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (count($preview['issues']) > 200): ?>
          <div class="table-note">Showing the first 200 of <?= (int) count($preview['issues']) ?> issue rows.</div>
        <?php endif; ?>
      <?php endif; ?>

      <h2 class="section-title">First <?= (int) count($preview['rows']) ?> of <?= (int) $preview['total'] ?> rows</h2>
      <div class="table-wrap">
        <table class="data table-compact">
          <thead>
            <tr>
              <th>Line</th><th>Tag</th><th>Brand / model</th><th>Description</th><th>Status</th><th>Cost</th><th>Purchased</th><th>Person</th><th>Department</th><th>Site</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($preview['rows'] as $pr): $c = $pr['row']; ?>
              <tr>
                <td class="nowrap"><?= (int) $pr['line'] ?></td>
                <td><?= e($c['asset_tag']) ?></td>
                <td><?= e(trim(($c['brand'] ?? '') . ' ' . ($c['model_number'] ?? ''))) ?></td>
                <td class="details-cell"><?= e($c['description']) ?></td>
                <td class="nowrap"><?= $c['status'] !== null ? e(status_label($c['status'])) : '—' ?></td>
                <td class="nowrap"><?= $c['purchase_cost'] > 0 ? e(money($c['purchase_cost'])) : '—' ?></td>
                <td class="nowrap"><?= e(date_fmt($c['purchase_date'])) ?></td>
                <td><?= e($c['person']) ?></td>
                <td><?= e($c['department']) ?></td>
                <td><?= e($c['site']) ?></td>
                <td><?= $pr['notes'] === [] ? '' : e(implode('; ', $pr['notes'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="form-foot">
      <button type="submit" class="btn btn-ghost" formaction="<?= e(url('/admin/import/analyze')) ?>">Re-analyze</button>
      <button type="submit" class="btn btn-primary" formaction="<?= e(url('/admin/import/run')) ?>">Import now</button>
    </div>
  </form>

<?php else: ?>
  <div class="cards-row animate-fadeup" style="animation-delay:.05s">
    <div class="stat-card accent-green"><div class="stat-num"><?= (int) $report['created'] ?></div><div class="stat-label">Created</div></div>
    <div class="stat-card accent-slate"><div class="stat-num"><?= (int) $report['skipped'] ?></div><div class="stat-label">Skipped (incl. duplicates)</div></div>
    <div class="stat-card accent-red"><div class="stat-num"><?= (int) $report['errors'] ?></div><div class="stat-label">Errors</div></div>
    <div class="stat-card accent-purple"><div class="stat-num"><?= (int) count($report['persons_created']) ?></div><div class="stat-label">Persons created</div></div>
    <div class="stat-card accent-blue"><div class="stat-num"><?= (int) $report['photos_downloaded'] ?></div><div class="stat-label">Photos downloaded</div></div>
    <div class="stat-card accent-amber"><div class="stat-num"><?= (int) $report['photos_failed'] ?></div><div class="stat-label">Photos failed</div></div>
  </div>

  <?php if ($report['persons_created'] !== []): ?>
    <div class="panel animate-fadeup" style="animation-delay:.08s">
      <div class="panel-head"><h2>Persons created</h2><span class="badge"><?= (int) count($report['persons_created']) ?></span></div>
      <div class="panel-body">
        <ul class="checklist">
          <?php foreach ($report['persons_created'] as $name): ?>
            <li><?= e($name) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ([
      'categories_created' => 'Categories created',
      'departments_created' => 'Departments created',
      'sites_created' => 'Sites created',
  ] as $key => $label): ?>
    <?php if ($report[$key] !== []): ?>
      <div class="panel animate-fadeup" style="animation-delay:.1s">
        <div class="panel-head"><h2><?= e($label) ?></h2><span class="badge"><?= (int) count($report[$key]) ?></span></div>
        <div class="panel-body">
          <ul class="checklist">
            <?php foreach ($report[$key] as $name): ?>
              <li><?= e($name) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="page-actions animate-fadeup" style="animation-delay:.12s">
    <a class="btn btn-primary" href="<?= e(url('/assets')) ?>">View assets</a>
    <a class="btn btn-ghost" href="<?= e(url('/admin/import')) ?>">Import another file</a>
  </div>
<?php endif; ?>
