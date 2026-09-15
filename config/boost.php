<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Agent Output Paths
    |--------------------------------------------------------------------------
    |
    | Cursor already has compact OMS-HRM project rules and a small root
    | AGENTS.md. Keep Laravel Boost's generated, package-wide guidelines
    | available for explicit inspection without loading them into every Cursor
    | conversation. Boost skills and MCP tools remain enabled and on demand.
    |
    */
    'agents' => [
        'cursor' => [
            'guidelines_path' => '.ai/generated/cursor-boost-guidelines.md',
        ],
    ],
];
