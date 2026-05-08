<?php

declare(strict_types=1);

namespace McpServer\Tools;

use McpServer\Attributes\McpFunction;

readonly class CalculatorTool {
    #[McpFunction(
        name: 'add_numbers',
        description: 'Performs basic addition on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function add(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a + $b is " . ($a + $b)
            ]
        ];
    }

    #[McpFunction(
        name: 'subtract_numbers',
        description: 'Performs basic subtraction on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function subtract(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a - $b is " . ($a - $b)
            ]
        ];
    }

    #[McpFunction(
        name: 'divide_numbers',
        description: 'Performs basic division on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function divide(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a / $b is " . (round($a / $b,2))
            ]
        ];
    }

    #[McpFunction(
        name: 'multiply_numbers',
        description: 'Performs basic multiplication on two numbers.',
        schema: [
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'number', 'description' => 'First number'],
                'b' => ['type' => 'number', 'description' => 'Second number']
            ],
            'required' => ['a', 'b']
        ]
    )]
    public function multiply(array $arguments): array {
        $a = $arguments['a'] ?? 0;
        $b = $arguments['b'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $a * $b is " . round($a * $b, 2)
            ]
        ];
    }

    #[McpFunction(
        name: 'power_numbers',
        description: 'Calculates a base number raised to a given exponent.',
        schema: [
            'type' => 'object',
            'properties' => [
                'base' => ['type' => 'number', 'description' => 'The base number'],
                'exponent' => ['type' => 'number', 'description' => 'The exponent']
            ],
            'required' => ['base', 'exponent']
        ]
    )]
    public function power(array $arguments): array {
        $base = $arguments['base'] ?? 0;
        $exponent = $arguments['exponent'] ?? 0;
        
        return [
            [
                'type' => 'text',
                'text' => "The result of $base ^ $exponent is " . round(pow($base, $exponent), 2)
            ]
        ];
    }

    #[McpFunction(
        name: 'calculate_loan_installment',
        description: 'Calculates the fixed monthly installment for a loan using the standard amortization formula.',
        schema: [
            'type' => 'object',
            'properties' => [
                'principal' => ['type' => 'number', 'description' => 'Total loan amount'],
                'annual_rate' => ['type' => 'number', 'description' => 'Annual interest rate in percent (e.g., 12 for 12%)'],
                'term_months' => ['type' => 'number', 'description' => 'Total number of monthly payments']
            ],
            'required' => ['principal', 'annual_rate', 'term_months']
        ]
    )]
    public function calculateLoanInstallment(array $arguments): array {
        $principal = $arguments['principal'] ?? 0;
        $annual_rate = $arguments['annual_rate'] ?? 0;
        $term_months = $arguments['term_months'] ?? 0;

        if ($term_months <= 0) {
            return [['type' => 'text', 'text' => 'Error: Loan term must be greater than 0.']];
        }

        $monthly_rate = ($annual_rate / 100) / 12;

        // Handle 0% interest case
        if ($monthly_rate == 0) {
            $installment = $principal / $term_months;
        } else {
            // Standard PMT formula: M = P * [r(1+r)^n] / [(1+r)^n - 1]
            $installment = $principal * ($monthly_rate * pow(1 + $monthly_rate, $term_months)) / (pow(1 + $monthly_rate, $term_months) - 1);
        }

        $total_paid = $installment * $term_months;
        $total_interest = $total_paid - $principal;

        return [
            [
                'type' => 'text',
                'text' => "Monthly Installment: " . round($installment, 2) . "\n" .
                          "Total Amount Paid: " . round($total_paid, 2) . "\n" .
                          "Total Interest Paid: " . round($total_interest, 2)
            ]
        ];
    }
}