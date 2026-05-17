<section class="page">
    <h1>Manage players</h1>
    <?php foreach ($flashes as $flash): ?>
        <p class="flash"><?= e($flash) ?></p>
    <?php endforeach; ?>

    <h2>GM privilege</h2>
    <form method="post" action="/admin/players/gm" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Character name<br><input type="text" name="player" required></label>
        <label>Action<br>
            <select name="grant">
                <option value="1">Grant GM</option>
                <option value="0">Revoke GM</option>
            </select>
        </label>
        <button type="submit" class="button">Apply</button>
    </form>

    <h2>Rename a player</h2>
    <form method="post" action="/admin/players/rename" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Current name<br><input type="text" name="old_name" required></label>
        <label>New name<br><input type="text" name="new_name" required></label>
        <button type="submit" class="button">Rename player</button>
    </form>

    <h2>Rename a sprite</h2>
    <form method="post" action="/admin/players/sprite" class="form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label>Character name<br><input type="text" name="player" required></label>
        <label>New sprite name<br><input type="text" name="sprite" required></label>
        <button type="submit" class="button">Rename sprite</button>
    </form>

    <p><a href="/admin">Back to dashboard</a></p>
</section>
