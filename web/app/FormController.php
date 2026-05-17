<?php

declare(strict_types=1);

namespace GfServer\App;

/**
 * Base for interactive (form-handling) controllers. Adds CSRF tokens, flash
 * messages and redirects on top of the plain Controller.
 */
abstract class FormController extends Controller
{
    public function __construct(View $view, protected readonly Session $session)
    {
        parent::__construct($view);
    }

    /** The per-session CSRF token, created on first use. */
    protected function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (!is_string($token) || $token === '') {
            $token = Csrf::generate();
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    /** True if the CSRF token submitted in $_POST matches the session token. */
    protected function csrfValid(): bool
    {
        $submitted = $_POST['csrf'] ?? null;

        return Csrf::check(
            is_string($this->session->get('csrf')) ? $this->session->get('csrf') : null,
            is_string($submitted) ? $submitted : null,
        );
    }

    /** Queue a flash message shown on the next rendered page. */
    protected function flash(string $message): void
    {
        $messages = $this->session->get('flash');
        $messages = is_array($messages) ? $messages : [];
        $messages[] = $message;
        $this->session->set('flash', $messages);
    }

    /**
     * Render a form page, auto-injecting the CSRF token and flash messages.
     *
     * @param array<string, mixed> $data
     */
    protected function page(string $template, array $data = [], int $status = 200): Response
    {
        $flashes = $this->session->get('flash');
        $this->session->remove('flash');

        $data += [
            'csrf' => $this->csrfToken(),
            'flashes' => is_array($flashes) ? $flashes : [],
        ];

        return $this->html($template, $data, $status);
    }

    protected function redirect(string $location): Response
    {
        return Response::redirect($location);
    }
}
