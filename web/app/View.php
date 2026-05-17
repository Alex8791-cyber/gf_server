<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Renders a PHP template and wraps its output in templates/layout.php.
 * Templates receive the data array as local variables and may call e().
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $templateDir)
    {
    }

    /**
     * Register data made available to every rendered template (e.g. the
     * logged-in user for the layout). Per-render data takes precedence.
     *
     * @param array<string, mixed> $data
     */
    public function share(array $data): void
    {
        $this->shared = $data + $this->shared;
    }

    /**
     * Render $template (without .php) wrapped in the layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $data += $this->shared;
        $content = $this->capture($template, $data);

        return $this->capture('layout', ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    private function capture(string $template, array $data): string
    {
        $file = "{$this->templateDir}/{$template}.php";
        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}
