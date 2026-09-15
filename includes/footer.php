    </div><!-- /.app-content -->
</div><!-- /.app-main -->
</div><!-- /.app-shell -->

<script src="<?= e(asset('js/app.js')) ?>"></script>
<!-- Mobile Bottom Navigation -->
<nav class="mobile-bottom-nav d-lg-none">

    <?php foreach ($menuItems as $item): ?>
        <?php if ($item['enabled']): ?>
            <a href="<?= e($item['url']) ?>"
               class="<?= $activeMenu === $item['key'] ? 'active' : '' ?>">
                <i class="bi <?= e($item['icon']) ?>"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>

</nav>
</body>
</html>
