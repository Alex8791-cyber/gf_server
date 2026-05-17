<section class="page">
    <h1>Change password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/account/password" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Current password<br><input type="password" name="current" required></label>
        <label>New password<br><input type="password" name="new" required></label>
        <button type="submit" class="button">Change password</button>
    </form>
    <p><a href="/account">Back to my account</a></p>
</section>
