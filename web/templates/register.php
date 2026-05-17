<section class="page">
    <h1>Create an account</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/register" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Email<br><input type="email" name="email" required></label>
        <label>Password<br><input type="password" name="password" required></label>
        <button type="submit" class="button">Register</button>
    </form>
    <p>Already have an account? <a href="/login">Log in</a>.</p>
</section>
