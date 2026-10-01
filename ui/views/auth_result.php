<?php if (!isset($result_url, $result_label, $escape)) { http_response_code(404); exit; } ?>
<a class="ma-submit" href="<?= $escape($result_url) ?>"><?= $escape($result_label) ?> <?php $icon('arrow'); ?></a>
<?php if ($auth_error && $result_url !== $path_to_root . '/index.php'): ?>
<p class="ma-back"><a class="ma-link" href="<?= $escape($path_to_root . '/index.php') ?>"><?= _('Back to sign in') ?></a></p>
<?php endif; ?>
