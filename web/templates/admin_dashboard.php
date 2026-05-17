<section class="page">
    <h1>Admin dashboard</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <p class="status-counts">
        Accounts: <strong><?= (int) $accounts ?></strong> &mdash;
        Characters: <strong><?= (int) $characters ?></strong>
    </p>
    <ul class="admin-menu">
        <li><a href="/admin/news">Manage news</a></li>
        <li><a href="/admin/players">Manage players</a></li>
        <li><a href="/admin/accounts">Manage accounts</a></li>
    </ul>
</section>
