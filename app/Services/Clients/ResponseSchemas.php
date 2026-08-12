<?php

namespace App\Services\Clients;

use App\Services\PromptingPipeline\Data\Directive;

/**
 * JSON schemas for the moderator's structured calls, passed to
 * OpenAIClient::sendFast() as `text.format`.
 *
 * The moderator prompts always produced parseable JSON, but nothing enforced
 * the *shape*: `role` came back as invented free text and the enums drifted.
 * Pinning the schema here keeps the contract next to the parser that consumes
 * it, and makes the defensive fallbacks in ModeratorService a real exception
 * path rather than an everyday one.
 *
 * `strict` requires every property to be listed in `required` and
 * `additionalProperties` to be false — the provider rejects the schema
 * otherwise, so keep those invariants when editing.
 */
class ResponseSchemas
{
    /** Candidate set + turn directive. Mirrors prompts/moderator/route.blade.php. */
    public static function route(): array
    {
        return self::wrap('route', [
            'type' => 'object',
            'properties' => [
                'candidates' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'pattern' => '^E[0-9]+$'],
                    'minItems' => 1,
                ],
                'directive' => [
                    'type' => 'object',
                    'properties' => [
                        'role' => ['type' => 'string', 'enum' => array_keys(Directive::ROLES)],
                        'agenda_step' => ['type' => 'string', 'enum' => ['divergenz', 'konvergenz', 'abschluss']],
                        'convergence_intent' => ['type' => 'string'],
                        'hand_back_to_user' => ['type' => 'boolean'],
                    ],
                    'required' => ['role', 'agenda_step', 'convergence_intent', 'hand_back_to_user'],
                    'additionalProperties' => false,
                ],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['candidates', 'directive', 'reasoning'],
            'additionalProperties' => false,
        ]);
    }

    /** Winner selection. Mirrors prompts/moderator/select.blade.php. */
    public static function select(): array
    {
        return self::wrap('select', [
            'type' => 'object',
            'properties' => [
                'winner' => ['type' => 'string', 'pattern' => '^E[0-9]+$'],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['winner', 'reasoning'],
            'additionalProperties' => false,
        ]);
    }

    /** Progress/closure verdict. Mirrors prompts/moderator/closure.blade.php. */
    public static function closure(): array
    {
        return self::wrap('closure', [
            'type' => 'object',
            'properties' => [
                'point_resolved' => ['type' => 'boolean'],
                'going_in_circles' => ['type' => 'boolean'],
                'next_move' => [
                    'type' => 'string',
                    'enum' => ['vertiefen', 'neuer_aspekt', 'konvergenz', 'abschluss', 'nutzer'],
                ],
                'open_question' => ['type' => 'string'],
                'zwischenergebnis' => ['type' => 'string'],
            ],
            'required' => ['point_resolved', 'going_in_circles', 'next_move', 'open_question', 'zwischenergebnis'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function wrap(string $name, array $schema): array
    {
        return [
            'type' => 'json_schema',
            'name' => $name,
            'strict' => true,
            'schema' => $schema,
        ];
    }
}
