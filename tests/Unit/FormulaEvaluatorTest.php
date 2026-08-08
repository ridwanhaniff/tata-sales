<?php

namespace Tests\Unit;

use App\Services\Calculator\FormulaEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FormulaEvaluatorTest extends TestCase
{
    public function test_basic_arithmetic(): void
    {
        $evaluator = new FormulaEvaluator;

        $this->assertSame(7.0, $evaluator->evaluate('2 + 5'));
        $this->assertSame(6.0, $evaluator->evaluate('2 * 3'));
        $this->assertSame(5.0, $evaluator->evaluate('10 / 2'));
        $this->assertSame(1.0, $evaluator->evaluate('3 - 2'));
        $this->assertSame(14.0, $evaluator->evaluate('2 + 3 * 4'));
        $this->assertSame(20.0, $evaluator->evaluate('(2 + 3) * 4'));
        $this->assertSame(-5.0, $evaluator->evaluate('-5'));
        $this->assertSame(1.5, $evaluator->evaluate('3 / 2'));
    }

    public function test_variables_are_resolved(): void
    {
        $evaluator = new FormulaEvaluator(['price' => 249500000, 'dp' => 50000000]);

        $this->assertSame(199500000.0, $evaluator->evaluate('price - dp'));
    }

    public function test_unknown_variable_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new FormulaEvaluator)->evaluate('harga * 2');
    }

    public function test_annuity_function(): void
    {
        $evaluator = new FormulaEvaluator;

        // harga 249.500.000, DP 50jt, tenor 60, bunga 6.5% → angsuran ~3.9jt
        $installment = $evaluator->evaluate('annuity(199500000, 6.5, 60)');

        $this->assertGreaterThan(3_800_000, $installment);
        $this->assertLessThan(4_000_000, $installment);
    }

    public function test_annuity_with_zero_interest_is_principal_over_tenor(): void
    {
        $evaluator = new FormulaEvaluator;

        $this->assertSame(500000.0, $evaluator->evaluate('annuity(6000000, 0, 12)'));
    }

    public function test_annuity_wrong_argument_count_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new FormulaEvaluator)->evaluate('annuity(1000000, 5)');
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new FormulaEvaluator)->evaluate('10 / (5 - 5)');
    }

    public function test_floor_ceil_round_functions(): void
    {
        $evaluator = new FormulaEvaluator;

        $this->assertSame(10.0, $evaluator->evaluate('floor(10.9)'));
        $this->assertSame(11.0, $evaluator->evaluate('ceil(10.1)'));
        $this->assertSame(10.0, $evaluator->evaluate('round(10.4)'));
        $this->assertSame(11.0, $evaluator->evaluate('round(10.5)'));
    }

    public function test_max_min_functions(): void
    {
        $evaluator = new FormulaEvaluator;

        $this->assertSame(9.0, $evaluator->evaluate('max(3, 7, 9, 2)'));
        $this->assertSame(2.0, $evaluator->evaluate('min(3, 7, 9, 2)'));
    }

    public function test_invalid_expression_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new FormulaEvaluator)->evaluate('2 +');
    }

    public function test_empty_formula_throws(): void
    {
        $this->expectException(RuntimeException::class);

        (new FormulaEvaluator)->evaluate('   ');
    }
}
