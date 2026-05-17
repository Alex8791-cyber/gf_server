<?php

declare(strict_types=1);

namespace GfServer\Tests;

use GfServer\App\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/gfview_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/layout.php', '[<?= $content ?>]');
        file_put_contents($this->dir . '/page.php', 'Hi <?= e($name) ?>');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/layout.php');
        @unlink($this->dir . '/page.php');
        @rmdir($this->dir);
    }

    public function testRenderWrapsTheTemplateInTheLayout(): void
    {
        $html = (new View($this->dir))->render('page', ['name' => 'Bob']);
        $this->assertSame('[Hi Bob]', $html);
    }

    public function testRenderEscapesDataViaTheEHelper(): void
    {
        $html = (new View($this->dir))->render('page', ['name' => '<script>']);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testRenderRejectsAMissingTemplate(): void
    {
        $this->expectException(\RuntimeException::class);
        (new View($this->dir))->render('does_not_exist');
    }

    public function testSharedDataIsAvailableToTemplates(): void
    {
        $view = new View($this->dir);
        $view->share(['name' => 'Shared']);
        $this->assertSame('[Hi Shared]', $view->render('page'));
    }
}
