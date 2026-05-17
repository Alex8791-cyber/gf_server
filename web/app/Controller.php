<?php

declare(strict_types=1);

namespace GfServer\App;

/** Base controller: gives subclasses a View and an html() helper. */
abstract class Controller
{
    public function __construct(protected readonly View $view)
    {
    }

    /**
     * Render a template (wrapped in the layout) into an HTML Response.
     *
     * @param array<string, mixed> $data
     */
    protected function html(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }
}
