<section class="page">
    <h1>My account</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <dl class="account-details">
        <dt>Username</dt><dd><?= e($username) ?></dd>
        <dt>Email</dt><dd><?= e($email ?? '(none)') ?></dd>
        <dt>Email verified</dt><dd><?= $verified ? 'Yes' : 'No' ?></dd>
    </dl>
    <p><a class="button" href="/account/password">Change password</a></p>
    <form method="post" action="/logout" class="form-inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <button type="submit" class="button button-secondary">Log out</button>
    </form>
</section>
