<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use PHPUnit\Framework\TestCase;

final class MantencionNativeActivityMigrationTest extends TestCase
{
    public function test_activity_routes_use_the_native_controller_before_the_legacy_section_route(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        self::assertIsString($routes);
        $nativeRoute = "Route::get('/redmine-mantencion/app/actividad', [MantencionActivityController::class, 'index'])";
        $legacyRoute = "Route::match(['GET', 'POST'], '/redmine-mantencion/app/{section}'";
        self::assertStringContainsString($nativeRoute, $routes);
        self::assertStringContainsString("[MantencionActivityController::class, 'clear']", $routes);
        self::assertLessThan(strpos($routes, $legacyRoute), strpos($routes, $nativeRoute));
    }

    public function test_native_activity_view_has_no_procedural_runtime_dependencies(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/native/activity.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString("@extends('redmine_mantencion::native.layout')", $view);
        self::assertStringContainsString('@csrf', $view);
        self::assertStringNotContainsString('require_once', $view);
        self::assertStringNotContainsString('auth_can(', $view);
        self::assertStringNotContainsString('legacy_csrf_token(', $view);
        self::assertStringNotContainsString('$_SESSION', $view);
        self::assertStringNotContainsString('$_POST', $view);
    }

    public function test_actor_matching_preserves_legacy_scope_rules(): void
    {
        $repository = new MantencionActivityRepository;

        self::assertTrue($repository->actorMatches('Jean Cortés', 'Jean Cortes'));
        self::assertTrue($repository->actorMatches('Nombre anterior', 'Nombre actual', '117', '117'));
        self::assertFalse($repository->actorMatches('Jean Cortés', 'Otro Usuario', '117', '122'));
        self::assertFalse($repository->actorMatches('Sistema', 'Jean Cortés'));
    }
}
