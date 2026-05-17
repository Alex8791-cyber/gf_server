<section class="page">
    <h1>Download the client</h1>
    <?php if ($downloadUrl === null): ?>
        <p>The client download will be available soon.</p>
    <?php else: ?>
        <p>Download the full game client to start playing:</p>
        <p><a class="button" href="<?= e($downloadUrl) ?>">Download client</a></p>
    <?php endif; ?>
</section>
