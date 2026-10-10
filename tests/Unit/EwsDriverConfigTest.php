<?php

namespace TahsinGokalp\LaravelEwsDriver\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TahsinGokalp\LaravelEwsDriver\Config\EwsDriverConfig;
use TahsinGokalp\LaravelEwsDriver\Exceptions\EwsException;

class EwsDriverConfigTest extends TestCase
{
    public function test_it_builds_config_from_array(): void
    {
        $config = EwsDriverConfig::fromArray([
            'host' => 'webmail.example.com',
            'username' => 'example-user',
            'password' => 'example-password',
            'version' => 'Exchange2016',
            'messageDispositionType' => 'SendOnly',
            'verifySsl' => true,
        ], 'sender@example.com');

        $this->assertSame('sender@example.com', $config->from);
        $this->assertSame('webmail.example.com', $config->host);
        $this->assertSame('example-user', $config->username);
        $this->assertSame('example-password', $config->password);
        $this->assertSame('Exchange2016', $config->version);
        $this->assertSame('SendOnly', $config->messageDispositionType);
        $this->assertTrue($config->verifySsl);
    }

    public function test_it_applies_defaults_for_optional_values(): void
    {
        $config = EwsDriverConfig::fromArray($this->requiredConfig() + [
            'version' => null,
            'messageDispositionType' => '',
        ]);

        $this->assertNull($config->from);
        $this->assertSame('Exchange2013', $config->version);
        $this->assertSame('SendAndSaveCopy', $config->messageDispositionType);
        $this->assertFalse($config->verifySsl);
    }

    public function test_empty_from_address_is_treated_as_missing(): void
    {
        $this->assertNull(EwsDriverConfig::fromArray($this->requiredConfig(), '')->from);
    }

    #[DataProvider('missingRequiredValues')]
    public function test_it_requires_connection_values(string $key, mixed $value): void
    {
        $config = $this->requiredConfig();
        if ($value === 'unset') {
            unset($config[$key]);
        } else {
            $config[$key] = $value;
        }

        $this->expectException(EwsException::class);
        $this->expectExceptionMessage("The exchange mailer requires the \"$key\" config value to be set.");

        EwsDriverConfig::fromArray($config);
    }

    public static function missingRequiredValues(): array
    {
        $cases = [];
        foreach (EwsDriverConfig::REQUIRED_KEYS as $key) {
            $cases["$key missing"] = [$key, 'unset'];
            $cases["$key null"] = [$key, null];
            $cases["$key empty"] = [$key, ''];
        }

        return $cases;
    }

    public function test_it_rejects_unknown_message_disposition_type(): void
    {
        $this->expectException(EwsException::class);
        $this->expectExceptionMessage('Invalid exchange "messageDispositionType" config value "SendLater"');

        EwsDriverConfig::fromArray($this->requiredConfig() + ['messageDispositionType' => 'SendLater']);
    }

    #[DataProvider('verifySslValues')]
    public function test_it_parses_verify_ssl_values(mixed $value, bool $expected): void
    {
        $config = EwsDriverConfig::fromArray($this->requiredConfig() + ['verifySsl' => $value]);

        $this->assertSame($expected, $config->verifySsl);
    }

    public static function verifySslValues(): array
    {
        return [
            'bool true' => [true, true],
            'bool false' => [false, false],
            'string true' => ['true', true],
            'string false' => ['false', false],
            'string 1' => ['1', true],
            'string 0' => ['0', false],
            'null' => [null, false],
        ];
    }

    private function requiredConfig(): array
    {
        return [
            'host' => 'webmail.example.com',
            'username' => 'example-user',
            'password' => 'example-password',
        ];
    }
}
