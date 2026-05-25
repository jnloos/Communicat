<?php

namespace App\Services\Text;

class MemoryFormatter
{
    /**
     * Parse a GEDÄCHTNIS-UPDATE block into structured sections.
     *
     * Expected canonical format (current prompt):
     *
     *     [NUTZER]
     *     ...
     *     [EXPERTE: Sophie Wagner]
     *     ...
     *     [OFFENE_FRAGEN]
     *     - ...
     *     - ...
     *     [STAND]
     *     ...
     *
     * Legacy free-text format (older Summaries written by the previous prompt)
     * uses lines like "Was ich über den Nutzer weiß: ..." and is preserved as
     * a `raw` block so the UI can fall back to monospace rendering.
     *
     * @return array{
     *     structured: bool,
     *     user: ?string,
     *     users: array<string, string>,
     *     experts: array<string, string>,
     *     open_questions: string[],
     *     state: ?string,
     *     raw: string,
     * }
     */
    public function parse(?string $memoryBlock): array
    {
        $raw = trim((string) $memoryBlock);

        $empty = [
            'structured'     => false,
            'user'           => null,
            'users'          => [],
            'experts'        => [],
            'open_questions' => [],
            'state'          => null,
            'raw'            => $raw,
        ];

        if ($raw === '') {
            return $empty;
        }

        // Strip any leading "GEDÄCHTNIS-UPDATE:" header that may still be
        // attached when the parser is called with raw LLM output.
        $body = preg_replace('/^\s*GEDÄCHTNIS-UPDATE:\s*\n?/u', '', $raw) ?? $raw;

        // Match a known header, a per-user [NUTZER: <name>], or a per-expert
        // [EXPERTE: <name>] (both preserving the name). Bare [NUTZER] is legacy.
        $sectionPattern = '/^\s*\[(NUTZER:\s*[^\]]+|NUTZER|OFFENE_FRAGEN|STAND|EXPERTE:\s*[^\]]+)\]\s*$/mu';

        if (preg_match_all($sectionPattern, $body, $headerMatches, PREG_OFFSET_CAPTURE) === 0
            || empty($headerMatches[0])) {
            return $empty;
        }

        $result = [
            'structured'     => true,
            'user'           => null,
            'users'          => [],
            'experts'        => [],
            'open_questions' => [],
            'state'          => null,
            'raw'            => $raw,
        ];

        $headers = $headerMatches[0];
        $count   = count($headers);

        for ($i = 0; $i < $count; $i++) {
            [$fullMatch, $offset] = $headers[$i];
            $headerLength = strlen($fullMatch);
            $contentStart = $offset + $headerLength;

            $contentEnd = $i + 1 < $count
                ? $headers[$i + 1][1]
                : strlen($body);

            $content = trim(substr($body, $contentStart, $contentEnd - $contentStart));
            $rawLabel = trim($headerMatches[1][$i][0]);

            // Per-user block [NUTZER: <name>].
            if (str_starts_with($rawLabel, 'NUTZER:')) {
                $name = trim(substr($rawLabel, strlen('NUTZER:')));
                if ($name !== '' && $content !== '') {
                    $result['users'][$name] = $content;
                }
                continue;
            }

            // Legacy single [NUTZER] block (older summaries).
            if ($rawLabel === 'NUTZER') {
                $result['user'] = $content !== '' ? $content : null;
                continue;
            }

            if ($rawLabel === 'OFFENE_FRAGEN') {
                $result['open_questions'] = $this->parseBulletList($content);
                continue;
            }

            if ($rawLabel === 'STAND') {
                $result['state'] = $content !== '' ? $content : null;
                continue;
            }

            // [EXPERTE: <name>]
            if (str_starts_with($rawLabel, 'EXPERTE:')) {
                $name = trim(substr($rawLabel, strlen('EXPERTE:')));
                if ($name !== '' && $content !== '') {
                    $result['experts'][$name] = $content;
                }
            }
        }

        // If nothing meaningful was extracted, treat as unstructured.
        if ($result['user'] === null
            && empty($result['users'])
            && $result['state'] === null
            && empty($result['experts'])
            && empty($result['open_questions'])) {
            return $empty;
        }

        return $result;
    }

    /**
     * Convert a content block into bullet items. Accepts both "- item" and
     * "* item" prefixes; drops empty lines and the literal "keine".
     *
     * @return string[]
     */
    protected function parseBulletList(string $content): array
    {
        $items = [];
        foreach (preg_split('/\r?\n/u', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $stripped = preg_replace('/^[-*]\s*/u', '', $trimmed) ?? $trimmed;
            $stripped = trim($stripped);

            if ($stripped === '' || mb_strtolower($stripped) === 'keine') {
                continue;
            }

            $items[] = $stripped;
        }

        return $items;
    }
}
