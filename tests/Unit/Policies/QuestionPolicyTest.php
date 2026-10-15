<?php

namespace Tests\Unit\Policies;

use App\Models\Question;
use App\Models\User;
use App\Policies\QuestionPolicy;
use Illuminate\Auth\Access\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionPolicyTest extends TestCase
{
    private const OWNER = 1;

    private const STRANGER = 2;

    /** @return array<string, array{bool, bool, bool, string, ?int}> owner?, template?, admin?, ability, expected status (null = allowed) */
    public static function cases(): array
    {
        return [
            'view own question' => [true, false, false, 'view', null],
            'view a foreign question' => [false, false, false, 'view', 404],
            'view a foreign template' => [false, true, false, 'view', null],
            'rename own question' => [true, false, false, 'update', null],
            'rename a foreign question' => [false, false, true, 'update', 404],
            'rename own template as non-admin' => [true, true, false, 'update', 403],
            'rename own template as admin' => [true, true, true, 'update', null],
            'rename a foreign template as admin' => [false, true, true, 'update', 404],
            'delete own template as non-admin' => [true, true, false, 'delete', 403],
            'delete own question' => [true, false, false, 'delete', null],
            'reorder own question' => [true, false, false, 'reorder', null],
            'reorder a template among questions' => [true, true, true, 'reorder', 404],
            'reorder a foreign question' => [false, false, false, 'reorder', 404],
            'duplicate own question' => [true, false, false, 'duplicate', null],
            'duplicate a template' => [true, true, false, 'duplicate', 404],
            'save own question as template, non-admin' => [true, false, false, 'saveAsTemplate', 403],
            'save own question as template, admin' => [true, false, true, 'saveAsTemplate', null],
            'save a foreign question as template, admin' => [false, false, true, 'saveAsTemplate', 404],
        ];
    }

    #[DataProvider('cases')]
    public function test_the_rules_of_the_statistics_page(bool $owner, bool $template, bool $admin, string $ability, ?int $expected): void
    {
        $user = $this->user(self::OWNER, $admin);
        $question = (new Question)->forceFill([
            'user_id' => $owner ? self::OWNER : self::STRANGER,
            'is_template' => $template,
        ]);

        /** @var Response $response */
        $response = (new QuestionPolicy)->{$ability}($user, $question);

        $this->assertSame($expected === null, $response->allowed());
        $this->assertSame($expected === 404 ? 404 : null, $response->status());
    }

    public function test_only_admins_manage_templates(): void
    {
        $this->assertTrue((new QuestionPolicy)->manageTemplates($this->user(self::OWNER, true)));
        $this->assertFalse((new QuestionPolicy)->manageTemplates($this->user(self::OWNER, false)));
    }

    private function user(int $id, bool $admin): User
    {
        return (new User)->forceFill(['id' => $id, 'is_admin' => $admin]);
    }
}
