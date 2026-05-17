<section class="page">
    <?php if ($item === null): ?>
        <h1>News not found</h1>
        <p>That news item does not exist. <a href="/news">Back to news</a>.</p>
    <?php else: ?>
        <article class="news-article">
            <h1><?= e($item['title']) ?></h1>
            <p class="news-date"><?= e(substr($item['published_at'], 0, 10)) ?></p>
            <div class="news-body"><?= nl2br(e($item['body'])) ?></div>
            <p><a href="/news">Back to news</a></p>
        </article>
    <?php endif; ?>
</section>
