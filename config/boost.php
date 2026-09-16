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
    | conversation. Framework skills are shared from .agents/skills so Cursor
    | does not discover duplicate copies under both supported skill roots.
    |
    */
    'agents' => [
        'cursor' => [
            'guidelines_path' => '.ai/generated/cursor-boost-guidelines.md',
            'skills_path' => '.agents/skills',
        ],
    ],
];
