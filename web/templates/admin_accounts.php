<section class="page">
    <h1>Manage accounts</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>

    <h2>Reset a password</h2>
    <form method="post" action="/admin/accounts/password" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>New password<br><input type="text" name="password" required></label>
        <button type="submit" class="button">Reset password</button>
    </form>

    <h2>Lock or unlock an account</h2>
    <form method="post" action="/admin/accounts/lock" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <label>Action<br>
            <select name="lock">
                <option value="1">Lock</option>
                <option value="0">Unlock</option>
            </select>
        </label>
        <button type="submit" class="button">Apply</button>
    </form>

    <h2>Resend confirmation email</h2>
    <form method="post" action="/admin/accounts/resend" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Username<br><input type="text" name="username" required></label>
        <button type="submit" class="button">Resend email</button>
    </form>

    <p><a href="/admin">Back to dashboard</a></p>
</section>
