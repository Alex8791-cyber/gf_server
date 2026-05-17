<section class="page">
    <h1>Set a new password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($token === ''): ?>
        <p><a href="/forgot">Request a new reset link</a>.</p>
    <?php else: ?>
        <form method="post" action="/reset" class="form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <label>New password<br><input type="password" name="new" required></label>
            <button type="submit" class="button">Set password</button>
        </form>
    <?php endif; ?>
</section>
