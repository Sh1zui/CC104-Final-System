<?php
/**
 * Bottom of every authenticated page. A page can set, before including:
 *   $page_scripts   — array of extra script URLs (loaded after app.js)
 *   $page_inline_js — a string of JS to run after those scripts load.
 *                      Not currently used by any page (Phase 3's dashboard
 *                      passes data via a JSON <script> block instead — see
 *                      admin/dashboard.php). Emitted raw, unescaped, by
 *                      design — it's for static/computed script text, never
 *                      for concatenating user input directly.
 */
$page_scripts   = $page_scripts ?? [];
$page_inline_js = $page_inline_js ?? '';
?>
        </main>
    </div><!-- /.main-column -->
</div><!-- /.app-shell -->

<script src="<?= e(BASE_URL) ?>assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= e(BASE_URL) ?>assets/js/app.js"></script>
<?php if (!empty($tour_steps)): ?>
<script src="<?= e(BASE_URL) ?>assets/js/tour.js"></script>
<?php endif; ?>
<?php foreach ($page_scripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
<?php if ($page_inline_js !== ''): ?>
<script>
<?= $page_inline_js ?>
</script>
<?php endif; ?>
</body>
</html>
