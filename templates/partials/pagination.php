<?php
/** @var int $page */
/** @var int $pages */
/** @var string $sort */
$query = $_GET ?? [];
$qs = static function (array $overrides) use ($query): string {
    $merged = array_merge($query, $overrides);
    return '?' . http_build_query($merged);
};
?>
<nav class="pagination" aria-label="Pagination">
  <a class="page-btn" href="<?= e(url($qs(['page' => max(1, $page - 1)]))) ?>" <?= $page <= 1 ? 'disabled' : '' ?>>‹ Prev</a>
  <?php
  $window = [];
  $start = max(1, $page - 2);
  $end = min($pages, $page + 2);
  for ($i = $start; $i <= $end; $i++) {
      $window[] = $i;
  }
  ?>
  <?php if ($start > 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
  <?php foreach ($window as $i): ?>
    <?php if ($i === $page): ?>
      <span class="page-btn active"><?= $i ?></span>
    <?php else: ?>
      <a class="page-btn" href="<?= e(url($qs(['page' => $i]))) ?>"><?= $i ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ($end < $pages): ?><span class="page-ellipsis">…</span><?php endif; ?>
  <a class="page-btn" href="<?= e(url($qs(['page' => min($pages, $page + 1)]))) ?>" <?= $page >= $pages ? 'disabled' : '' ?>>Next ›</a>
</nav>
