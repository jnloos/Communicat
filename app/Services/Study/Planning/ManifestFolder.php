<?php

namespace App\Services\Study\Planning;

use JsonException;

/**
 * Thesis study, see docs/study.md.
 *
 * A study kept as a folder of parts under resources/study/manifests/<name>:
 * study.json (head), names.json (name pools) and scenarios/sNN.json, one
 * scenario each. assemble() joins them into the one manifest JSON the planner
 * reads; its canonical form, and with it every hash, does not depend on how the
 * parts are laid out. Only the folder is checked here; the content is the
 * planner's (Manifest::fromJson).
 */
final class ManifestFolder
{
    public const ROOT = 'study/manifests';

    /** @return list<string> the folders that hold a study, by name */
    public static function names(): array
    {
        $names = array_map('basename', array_map('dirname', glob(resource_path(self::ROOT.'/*/study.json')) ?: []));
        sort($names, SORT_NATURAL);

        return $names;
    }

    public static function path(string $name): string
    {
        return resource_path(self::ROOT.'/'.$name);
    }

    /** @throws InvalidManifest when a part is missing or unreadable, or the scenario files do not run s01, s02, … */
    public static function assemble(string $folder): string
    {
        $study = self::part("{$folder}/study.json");
        $names = self::part("{$folder}/names.json");

        $overlap = array_keys(array_intersect_key($study, $names));
        if ($overlap !== []) {
            throw new InvalidManifest(['study.json and names.json both define: '.implode(', ', $overlap).'.']);
        }

        return json_encode($study + $names + ['scenarios' => self::scenarios($folder)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** @return list<array<string, mixed>> */
    private static function scenarios(string $folder): array
    {
        $files = glob("{$folder}/scenarios/*.json") ?: [];
        natsort($files);

        if ($files === []) {
            throw new InvalidManifest(["No scenario in {$folder}/scenarios (files s01.json, s02.json, …)."]);
        }

        $scenarios = [];
        foreach (array_values($files) as $index => $file) {
            $expected = sprintf('s%02d.json', $index + 1);
            if (basename($file) !== $expected) {
                throw new InvalidManifest([basename($file).": expected {$expected}; scenarios run s01, s02, … without gaps."]);
            }

            $scenario = self::part($file);
            if (($scenario['id'] ?? null) !== $index + 1) {
                throw new InvalidManifest([basename($file).': "id" is '.json_encode($scenario['id'] ?? null).', the file name says '.($index + 1).'.']);
            }

            $scenarios[] = $scenario;
        }

        return $scenarios;
    }

    /** @return array<string, mixed> */
    private static function part(string $file): array
    {
        if (! is_file($file)) {
            throw new InvalidManifest(['Missing '.basename($file).'.']);
        }

        try {
            $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidManifest([basename($file).': not valid JSON ('.$exception->getMessage().').']);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidManifest([basename($file).' must be a JSON object.']);
        }

        return $data;
    }
}
