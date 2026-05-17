<section class="page">
    <h1>News</h1>
    <?php if ($items === []): ?>
        <p>No news yet.</p>
    <?php else: ?>
        <ul class="news-list">
            <?php foreach ($items as $item): ?>
                <li>
                    <a href="/news?id=<?= (int) $item['id'] ?>"><?= e($item['title']) ?></a>
                    <span class="news-date"><?= e(substr($item['published_at'], 0, 10)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
