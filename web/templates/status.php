<section class="page">
    <h1>Server Status</h1>
    <ul class="status-list">
        <?php foreach ($components as $name => $online): ?>
            <li>
                <span class="status-name"><?= e($name) ?></span>
                <?php if ($online): ?>
                    <span class="status-up">Online</span>
                <?php else: ?>
                    <span class="status-down">Offline</span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <p class="status-counts">
        Registered accounts: <strong><?= (int) $accounts ?></strong> &mdash;
        Characters created: <strong><?= (int) $characters ?></strong>
    </p>
</section>
