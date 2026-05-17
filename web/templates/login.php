<section class="page">
    <h1>Log in</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Password<br><input type="password" name="password" required></label>
        <button type="submit" class="button">Log in</button>
    </form>
    <p><a href="/forgot">Forgot your password?</a></p>
    <p>No account yet? <a href="/register">Register</a>.</p>
</section>
