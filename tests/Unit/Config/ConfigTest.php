<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\Config;
use App\Config\ConfigException;
use App\Config\DotEnv;
use App\Support\Paths;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParsesCommentsQuotesAndExport(): void
    {
        $values = DotEnv::parse(<<<'ENV'
            # comment
            export A=1
            B="two words"
            C='raw $value'
            D=plain # trailing comment
            E=
            ENV);

        self::assertSame(['A' => '1', 'B' => 'two words', 'C' => 'raw $value', 'D' => 'plain', 'E' => ''], $values);
    }

    public function testRejectsLineWithoutEquals(): void
    {
        $this->expectException(ConfigException::class);
        DotEnv::parse("SECRET_VALUE_LINE");
    }

    public function testMissingRequiredKeyNamesOnlyTheKey(): void
    {
        $config = new Config(['TELEGRAM_BOT_TOKEN' => ''], new Paths('/tmp'));

        try {
            $config->assertPresent(['TELEGRAM_BOT_TOKEN', 'NOTION_TOKEN']);
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertSame('Missing required configuration: TELEGRAM_BOT_TOKEN, NOTION_TOKEN', $e->getMessage());
        }
    }

    public function testMaskedHidesSecrets(): void
    {
        $config = new Config([
            'TELEGRAM_BOT_TOKEN' => '123:abc',
            'TELEGRAM_RECIPIENTS' => 'eduardo:42',
            'APP_ENV' => 'local',
        ], new Paths('/tmp'));

        self::assertSame(
            ['APP_ENV' => 'local', 'TELEGRAM_BOT_TOKEN' => '***', 'TELEGRAM_RECIPIENTS' => '***'],
            $config->masked(),
        );
    }

    public function testTypedAccessors(): void
    {
        $config = new Config(['N' => '60', 'B' => 'false', 'X' => 'abc'], new Paths('/tmp'));

        self::assertSame(60, $config->int('N'));
        self::assertSame(10, $config->int('MISSING', 10));
        self::assertFalse($config->bool('B', true));

        $this->expectException(ConfigException::class);
        $config->int('X');
    }

    public function testRealEnvironmentOverridesDotEnv(): void
    {
        $root = sys_get_temp_dir() . '/cfg-' . bin2hex(random_bytes(4));
        mkdir($root);
        file_put_contents($root . '/.env', "LINKEDIN_PARENT_PAGE_ID=\nAPP_ENV=production\n");
        putenv('LINKEDIN_PARENT_PAGE_ID=from-env');

        try {
            $config = Config::load(new Paths($root));
            self::assertSame('from-env', $config->string('LINKEDIN_PARENT_PAGE_ID'));
            self::assertSame('production', $config->string('APP_ENV'));
        } finally {
            putenv('LINKEDIN_PARENT_PAGE_ID');
            unlink($root . '/.env');
            rmdir($root);
        }
    }
}
