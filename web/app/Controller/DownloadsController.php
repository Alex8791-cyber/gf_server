<?php

declare(strict_types=1);

namespace GfServer\App\Controller;

use GfServer\App\Controller;
use GfServer\App\Response;

/** The client download page. */
final class DownloadsController extends Controller
{
    public function index(): Response
    {
        $url = getenv('GF_DOWNLOAD_URL');
        $downloadUrl = ($url !== false && $url !== '') ? $url : null;

        return $this->html('downloads', [
            'title' => 'Download',
            'downloadUrl' => $downloadUrl,
        ]);
    }
}
