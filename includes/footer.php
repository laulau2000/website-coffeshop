    </main>
  </div><!-- /.main -->
</div><!-- /.app-shell -->

<div id="toast-wrap"></div>
<script src="<?= e(BASE_URL) ?>assets/js/app.js?v=<?= e(APP_VERSION) ?>"></script>
<?php if (!empty($page_scripts)) foreach ($page_scripts as $s): ?>
<script src="<?= e(BASE_URL . $s) ?>"></script>
<?php endforeach; ?>
</body>
</html>
