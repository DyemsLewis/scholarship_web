<?php

namespace App\Support;

final class AdminWorkPriority
{
    public static function make(
        string $title,
        string $reason,
        string $owner,
        ?array $action = null,
        ?string $timing = null,
        string $state = 'actionable',
    ): array {
        if ($action && filled($action['url'] ?? null)) {
            $parts = parse_url($action['url']);
            $action['url'] = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        }

        return compact('title', 'reason', 'owner', 'action', 'timing', 'state');
    }
}
