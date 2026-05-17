<section class="page">
    <h1>Rankings</h1>
    <?php if ($players === []): ?>
        <p>No characters yet.</p>
    <?php else: ?>
        <table class="ranking-table">
            <thead>
                <tr><th>#</th><th>Character</th><th>Level</th></tr>
            </thead>
            <tbody>
                <?php foreach ($players as $index => $player): ?>
                    <tr>
                        <td><?= (int) $index + 1 ?></td>
                        <td><?= e($player['given_name']) ?></td>
                        <td><?= (int) $player['level'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
