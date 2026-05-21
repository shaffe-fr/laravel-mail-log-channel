<?php

namespace Shaffe\MailLogChannel\Tests;

use PHPUnit\Framework\TestCase;
use Shaffe\MailLogChannel\Console\TestMailLogCommand;

class TestMailLogCommandTest extends TestCase
{
    public function test_command_has_correct_signature(): void
    {
        $command = new TestMailLogCommand();

        $this->assertEquals('mail-log:test', $command->getName());
    }

    public function test_command_has_level_option(): void
    {
        $command = new TestMailLogCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('level'));
        $this->assertEquals('error', $definition->getOption('level')->getDefault());
    }

    public function test_command_has_channel_option(): void
    {
        $command = new TestMailLogCommand();
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('channel'));
        $this->assertEquals('mail', $definition->getOption('channel')->getDefault());
    }
}
