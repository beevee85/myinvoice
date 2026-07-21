<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action\Codebook;

use MyInvoice\Action\Codebook\VatClassificationsAction;
use PHPUnit\Framework\TestCase;

final class VatClassificationsActionValidationTest extends TestCase
{
    public function testKhSpecialAttributesAcceptOnlyDocumentedValues(): void
    {
        $reflection = new \ReflectionClass(VatClassificationsAction::class);
        $action = $reflection->newInstanceWithoutConstructor();
        $validate = $reflection->getMethod('validate');
        $base = ['code' => 'T90', 'label' => 'Test', 'direction' => 'sale'];

        self::assertNull($validate->invoke($action, $base + ['kh_regime_code' => '2', 'kh_bad_debt' => 'P'], false));
        self::assertStringContainsString('kh_regime_code', (string) $validate->invoke($action, $base + ['kh_regime_code' => '9'], false));
        self::assertStringContainsString('kh_bad_debt', (string) $validate->invoke($action, $base + ['kh_bad_debt' => 'X'], false));
    }
}
