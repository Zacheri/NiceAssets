<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e($app_name ?? 'Nice Assets') ?></title>
<link rel="stylesheet" href="<?= e(asset_url('css/app.css')) ?>">
<link rel="icon" href="<?= e(asset_url('img/favicon.svg')) ?>">
</head>
<body class="auth-body">
<div class="auth-wrap">
  <div class="auth-card animate-fadeup">
    <div class="auth-brand">
      <div class="brand-mark brand-mark-lg">N</div>
      <h1><?= e($app_name ?? 'Nice Assets') ?></h1>
      <p>Local network asset tracking</p>
    </div>
    <?php if (!empty($error)): ?>
      <div class="toast toast-error" style="margin-bottom:16px"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('/login')) ?>" class="auth-form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Username</span>
        <input class="input" type="text" name="username" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
      </label>
      <label class="field">
        <span>Password</span>
        <input class="input" type="password" name="password" required autocomplete="current-password" minlength="8">
      </label>
      <button type="submit" class="btn btn-primary btn-block">Sign in</button>
    </form>
  </div>
  <div class="auth-foot">Passwords must be at least 8 characters.</div>
</div>
</body>
</html>
