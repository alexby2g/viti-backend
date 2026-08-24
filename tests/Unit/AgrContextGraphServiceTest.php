<?php

namespace Tests\Unit;

use Tests\TestCase;

class AgrContextGraphServiceTest extends TestCase
{
    public function test_context_graph_service_file_exists(): void
    {
        $this->assertFileExists(base_path('app/Services/AgrContextGraphService.php'));
        $contents = file_get_contents(base_path('app/Services/AgrContextGraphService.php'));

        $this->assertStringContainsString("approved_requests_without_project", $contents);
        $this->assertStringContainsString("next_attention", $contents);
        $this->assertStringContainsString("solicitudes.proyecto", $contents);
    }
}
