<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionManualReportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MantencionNativeManualMigrationTest extends TestCase
{
    public function test_manual_routes_use_the_native_controller_before_the_legacy_section_route(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        self::assertIsString($routes);
        $nativeRoute = "Route::get('/redmine-mantencion/app/manual', [MantencionManualController::class, 'index'])";
        $legacyRoute = "Route::match(['GET', 'POST'], '/redmine-mantencion/app/{section}'";
        self::assertStringContainsString($nativeRoute, $routes);
        self::assertStringContainsString("[MantencionManualController::class, 'store']", $routes);
        self::assertLessThan(strpos($routes, $legacyRoute), strpos($routes, $nativeRoute));
    }

    public function test_native_manual_view_uses_blade_session_and_csrf(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/views/native/manual.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString("@extends('redmine_mantencion::native.layout')", $view);
        self::assertStringContainsString('@csrf', $view);
        self::assertStringContainsString("route('redmine.mantencion.manual.store')", $view);
        self::assertStringNotContainsString('require_once', $view);
        self::assertStringNotContainsString('auth_can(', $view);
        self::assertStringNotContainsString('legacy_csrf_token(', $view);
        self::assertStringNotContainsString('$_SESSION', $view);
        self::assertStringNotContainsString('$_POST', $view);
    }

    public function test_native_views_do_not_use_inline_php_directives(): void
    {
        $views = glob(dirname(__DIR__, 2).'/RedmineMantencion/views/native/*.blade.php');

        self::assertIsArray($views);
        self::assertNotEmpty($views);

        foreach ($views as $viewPath) {
            $view = file_get_contents($viewPath);

            self::assertIsString($view);
            self::assertDoesNotMatchRegularExpression(
                '/@php\s*\(/',
                $view,
                basename($viewPath).' no debe usar @php(...) porque Blade lo interpreta como un bloque PHP abierto.'
            );
        }
    }

    public function test_native_service_persists_only_the_new_record_through_the_repository(): void
    {
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionManualReportService.php'
        );

        self::assertIsString($service);
        self::assertStringContainsString('$this->reports->upsertMessage($record, $config)', $service);
        self::assertStringContainsString("'fuente' => 'manual'", $service);
        self::assertStringContainsString("'estado' => 'pendiente'", $service);
        self::assertStringNotContainsString('load_messages(', $service);
        self::assertStringNotContainsString('save_messages(', $service);
        self::assertStringNotContainsString('require_once', $service);
    }

    public function test_native_controller_preserves_permission_and_maintenance_guards(): void
    {
        $controller = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Controllers/MantencionManualController.php'
        );

        self::assertIsString($controller);
        self::assertStringContainsString("can(\$context, 'simulador')", $controller);
        self::assertStringContainsString("\$context['maintenance']['enabled']", $controller);
        self::assertStringContainsString('->withInput()', $controller);
    }

    public function test_native_service_normalizes_manual_fields_like_the_legacy_form(): void
    {
        $reflection = new ReflectionClass(MantencionManualReportService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        self::assertSame('2026-08-04', $reflection->getMethod('date')->invoke($service, '04-08-2026'));
        self::assertSame('', $reflection->getMethod('date')->invoke($service, '31-02-2026'));
        self::assertSame('1.5', $reflection->getMethod('hours')->invoke($service, '1,50'));
        self::assertSame('+56912345678', $reflection->getMethod('phone')->invoke($service, '9 1234 5678'));
    }
}
