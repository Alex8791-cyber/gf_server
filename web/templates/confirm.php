<section class="page">
    <?php if ($ok): ?>
        <h1>Account confirmed</h1>
        <p>Your email is verified and your account is active. You can log in now.</p>
        <p><a class="button" href="/login">Log in</a></p>
    <?php else: ?>
        <h1>Confirmation failed</h1>
        <p>That confirmation link is invalid or has expired.</p>
        <p><a href="/login">Back to login</a></p>
    <?php endif; ?>
</section>
