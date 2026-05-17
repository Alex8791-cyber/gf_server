<section class="page">
    <h1><?= e($title) ?></h1>
    <?php if ($error !== null): ?>
        <p class="form-error"><?= e($error) ?></p>
    <?php endif; ?>
    <?php if ($item !== null || $action === '/admin/news/new'): ?>
        <form method="post" action="<?= e($action) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <?php if ($item !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <?php endif; ?>
            <label>Title<br>
                <input type="text" name="title" value="<?= e($item['title'] ?? '') ?>" required>
            </label>
            <label>Body<br>
                <textarea name="body" rows="10" required><?= e($item['body'] ?? '') ?></textarea>
            </label>
            <button type="submit" class="button">Save</button>
        </form>
    <?php endif; ?>
    <p><a href="/admin/news">Back to news list</a></p>
</section>
