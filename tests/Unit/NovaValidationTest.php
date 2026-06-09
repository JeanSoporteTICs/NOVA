<?php

namespace Tests\Unit;

use App\Support\Nova\NovaValidation;
use PHPUnit\Framework\TestCase;

class NovaValidationTest extends TestCase
{
    public function test_normalizes_rut_user_from_common_formats(): void
    {
        $this->assertSame('19006667', NovaValidation::normalizeRutUser('19.006.667-3'));
        $this->assertSame('19006667', NovaValidation::normalizeRutUser('190066673'));
        $this->assertSame('13818472', NovaValidation::normalizeRutUser('13.818.472-5'));
    }

    public function test_validates_email(): void
    {
        $this->assertTrue(NovaValidation::validEmail('jean.cortes@redsalud.gob.cl'));
        $this->assertFalse(NovaValidation::validEmail('correo inválido'));
    }
}
