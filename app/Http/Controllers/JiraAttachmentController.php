<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Jira\JiraApiException;
use App\Services\Jira\JiraAttachmentProxy;
use App\Services\Jira\JiraClient;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JiraAttachmentController extends Controller
{
    /**
     * Proxy a Jira attachment through the authenticated user's credentials so
     * inline images in issue descriptions and comments render in the browser
     * (Jira attachment endpoints require authentication the browser can't send).
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        abort_unless($user?->hasJiraConnection(), 404);

        $path = (string) $request->query('path', '');

        abort_unless(JiraAttachmentProxy::isAllowedPath($path), 404);

        try {
            $jiraResponse = JiraClient::forUser($user)->getAttachment($path);
        } catch (JiraApiException) {
            abort(404);
        }

        return response($jiraResponse->body(), Response::HTTP_OK, [
            'Content-Type' => $jiraResponse->header('Content-Type') ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
