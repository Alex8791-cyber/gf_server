<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Grand Fantasia') ?> &mdash; Grand Fantasia</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <header class="site-header">
        <a class="brand" href="/">Grand Fantasia</a>
        <nav>
            <a href="/">Home</a>
            <a href="/news">News</a>
            <a href="/downloads">Download</a>
            <a href="/status">Status</a>
            <a href="/rankings">Rankings</a>
        </nav>
    </header>
    <main class="site-main">
        <?= $content ?>
    </main>
    <footer class="site-footer">
        <p>Grand Fantasia private server.</p>
    </footer>
</body>
</html>
