<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;
use GfServer\App\View;
use GfServer\ServerStatus;

/** The live server-status page. */
final class StatusController extends Controller
{
    /** Component display name => local TCP port to probe. */
    private const COMPONENTS = [
        'Login Server' => 6543,
        'Gateway Server' => 5560,
        'Ticket Server' => 7777,
    ];

    public function __construct(View $view, private readonly ServerStatus $status)
    {
        parent::__construct($view);
    }

    public function index(): Response
    {
        $components = [];
        foreach (self::COMPONENTS as $name => $port) {
            $components[$name] = $this->status->isPortOpen('127.0.0.1', $port);
        }

        return $this->html('status', [
            'title' => 'Server Status',
            'components' => $components,
            'accounts' => $this->status->accountCount(),
            'characters' => $this->status->characterCount(),
        ]);
    }
}
