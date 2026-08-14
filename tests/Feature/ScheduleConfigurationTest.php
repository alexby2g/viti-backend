<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleConfigurationTest extends TestCase
{
    public function test_scheduler_lists_the_viti_database_copy_command(): void
    {
        $exit = Artisan::call('schedule:list');

        $this->assertSame(0,$exit);
        $this->assertStringContainsString('viti:backup-database',Artisan::output());
    }
}
