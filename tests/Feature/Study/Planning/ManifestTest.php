<?php

namespace Tests\Feature\Study\Planning;

use App\Study\Planning\InvalidManifest;
use App\Study\Planning\Manifest;
use App\Study\Planning\Planner;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManifestTest extends TestCase
{
    public function test_the_probe_manifest_is_valid(): void
    {
        $manifest = Manifest::fromJson(self::fixture('probe-manifest.json'));

        $this->assertSame('probe', $manifest->study());
        $this->assertCount(6, $manifest->scenarios());
    }

    public function test_runs_per_scenario_match_what_the_planner_makes(): void
    {
        $manifest = Manifest::fromJson(self::fixture('tiny-manifest.json'));

        $this->assertSame(count((new Planner)->plan($manifest, 1, 1)), $manifest->runsPer(1));
    }

    /** @return array<string, array{Closure(array): array, string}> */
    public static function rejections(): array
    {
        return [
            'duplicate id' => [function (array $m) {
                $m['last_names']['AL'][1]['id'] = $m['last_names']['AL'][0]['id'];

                return $m;
            }, 'Duplicate name id L-AL-01'],
            'missing description C' => [function (array $m) {
                unset($m['scenarios'][1]['descriptions']['C']);

                return $m;
            }, 'Scenario 2: description C'],
            '12 mod k is not 0' => [function (array $m) {
                foreach (['GH', 'IK'] as $code) {
                    $m['subgroups'][$code] = ['ethnicity' => 'E+', 'country' => "Land {$code}"];
                    $m['first_names'][$code] = $m['first_names']['AL'];
                    $m['last_names'][$code] = $m['last_names']['AL'];
                }

                return $m;
            }, '5 subgroups with ethnicity E+'],
            'too few first names' => [function (array $m) {
                $m['first_names']['BO']['G-'] = array_slice($m['first_names']['BO']['G-'], 0, 2);

                return $m;
            }, 'Subgroup BO, G-: at least 3 first names'],
            'capacity exhausted' => [function (array $m) {
                // Leave (G+, BO) only as many pairs as one scenario needs: scenario 2 runs dry.
                $m['first_names']['BO']['G+'] = array_slice($m['first_names']['BO']['G+'], 0, 3);
                $m['last_names']['BO'] = array_slice($m['last_names']['BO'], 0, 3);

                return $m;
            }, 'no name left in pot G+E+, subgroup BO'],
            'unknown model' => [fn (array $m) => ['model' => 'nobody-1'] + $m, 'Unknown model nobody-1'],
            'unknown pipeline' => [fn (array $m) => ['pipelines' => ['ScorePipeline', 'NoSuchPipeline']] + $m, 'Unknown pipeline NoSuchPipeline'],
            'duplicate pipeline' => [fn (array $m) => ['pipelines' => ['ScorePipeline', 'ScorePipeline']] + $m, 'Duplicate pipeline ScorePipeline'],
            'unknown seating' => [fn (array $m) => ['seating' => 'random'] + $m, 'seating must be "table" or left out'],
            'seat table without three pipelines' => [fn (array $m) => ['seating' => 'table', 'pipelines' => ['ScorePipeline', 'OrchestratorPipeline']] + $m, 'seating "table" needs exactly 3 pipelines'],
            'line break' => [function (array $m) {
                $m['scenarios'][0]['titles']['high'] = "Head\nLibrarian";

                return $m;
            }, 'line break'],
            'line break in a short first name' => [function (array $m) {
                $m['first_names']['AL']['G+'][0]['name'] = "A\nB";

                return $m;
            }, 'Subgroup AL, G+, first names: name: An attribute value must not contain a line break'],
            'empty last name' => [function (array $m) {
                $m['last_names']['AL'][0]['name'] = '';

                return $m;
            }, 'Subgroup AL, last names: name must not be empty'],
            'not NFC' => [function (array $m) {
                $m['subgroups']['AL']['country'] = "Alde\u{0301}ria";

                return $m;
            }, 'not in Unicode NFC'],
            'not trimmed' => [function (array $m) {
                $m['scenarios'][0]['topic'] .= ' ';

                return $m;
            }, 'not trimmed'],
            'gap in the scenario ids' => [function (array $m) {
                $m['scenarios'][2]['id'] = 4;

                return $m;
            }, 'Scenario at position 3 has id 4'],
            'equal titles' => [function (array $m) {
                $m['scenarios'][0]['titles']['low'] = $m['scenarios'][0]['titles']['high'];

                return $m;
            }, 'Scenario 1: titles high and low are the same'],
            'equal descriptions' => [function (array $m) {
                $m['scenarios'][0]['descriptions']['B'] = $m['scenarios'][0]['descriptions']['A'];

                return $m;
            }, 'Scenario 1: descriptions A, B and C must differ'],
            'template without :value' => [function (array $m) {
                $m['attributes']['Gender']['self'] = 'You are a woman.';

                return $m;
            }, 'Attribute Gender: self template'],
            'study name' => [fn (array $m) => ['study' => 'Main 2'] + $m, 'study must be lower-case letters'],
        ];
    }

    #[DataProvider('rejections')]
    public function test_each_problem_is_rejected(Closure $change, string $expected): void
    {
        $data = $change(json_decode(self::fixture('probe-manifest.json'), true));

        try {
            Manifest::fromJson(json_encode($data, JSON_UNESCAPED_UNICODE));
            $this->fail('The manifest was accepted.');
        } catch (InvalidManifest $exception) {
            $this->assertStringContainsString($expected, implode("\n", $exception->problems));
        }
    }

    public function test_all_problems_are_collected(): void
    {
        $data = ['model' => 'nobody-1', 'turn_budget' => 0] + json_decode(self::fixture('probe-manifest.json'), true);

        try {
            Manifest::fromJson(json_encode($data));
            $this->fail('The manifest was accepted.');
        } catch (InvalidManifest $exception) {
            $this->assertCount(2, $exception->problems);
        }
    }

    public function test_broken_json_is_rejected(): void
    {
        $this->expectException(InvalidManifest::class);

        Manifest::fromJson('{"schema": 1,');
    }

    public function test_the_canonical_hash_ignores_key_order_and_whitespace(): void
    {
        $json = self::fixture('probe-manifest.json');
        $reordered = json_encode(self::reverseKeys(json_decode($json, true)), JSON_UNESCAPED_UNICODE);

        $original = Manifest::fromJson($json);
        $other = Manifest::fromJson($reordered);

        $this->assertSame($original->sha256(), $other->sha256());
        $this->assertSame($original->coreHash(), $other->coreHash());
        $this->assertSame($original->canonical(), $other->canonical());
    }

    public function test_an_appended_scenario_keeps_the_core_and_the_old_scenario_hashes(): void
    {
        $data = json_decode(self::fixture('probe-manifest.json'), true);
        $original = Manifest::fromJson(json_encode($data));

        $data['scenarios'][] = ['id' => 7] + array_slice($data['scenarios'][0], 1);
        $data['scenarios'][6]['topic'] = 'Should the ferry run at night?';
        $appended = Manifest::fromJson(json_encode($data));

        $this->assertSame($original->coreHash(), $appended->coreHash());
        foreach (range(1, 6) as $scenario) {
            $this->assertSame($original->scenarioHash($scenario), $appended->scenarioHash($scenario));
        }
        $this->assertNotSame($original->sha256(), $appended->sha256());
    }

    public static function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../fixtures/'.$name);
    }

    private static function reverseKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $mapped = array_map(self::reverseKeys(...), $value);

        return array_is_list($value) ? $mapped : array_reverse($mapped, true);
    }
}
