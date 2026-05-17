<section class="page">
    <h1>Reset your password</h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <p>Enter your email address and we will send you a reset link.</p>
    <form method="post" action="/forgot" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Email<br><input type="email" name="email" required></label>
        <button type="submit" class="button">Send reset link</button>
    </form>
    <p><a href="/login">Back to login</a></p>
</section>
