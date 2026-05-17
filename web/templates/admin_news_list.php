<section class="page">
    <h1>Manage news</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>
    <p><a class="button" href="/admin/news/new">New post</a></p>
    <?php if ($items === []): ?>
        <p>No news posts yet.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead>
                <tr><th>Title</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['title']) ?></td>
                        <td><?= $item['published_at'] !== null ? 'Published' : 'Draft' ?></td>
                        <td class="admin-actions">
                            <a href="/admin/news/edit?id=<?= (int) $item['id'] ?>">Edit</a>
                            <form method="post" action="/admin/news/publish">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="publish" value="<?= $item['published_at'] !== null ? '0' : '1' ?>">
                                <button type="submit"><?= $item['published_at'] !== null ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" action="/admin/news/delete">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
