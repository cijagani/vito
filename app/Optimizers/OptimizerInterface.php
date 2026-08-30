<?php

namespace App\Optimizers;

use App\DTOs\Budget;
use App\DTOs\ServerFacts;
use App\DTOs\TuningProposal;

interface OptimizerInterface
{
    /**
     * The ruleset this optimizer evaluates, matching a `component` in
     * resources/optimization/rules.
     */
    public static function component(): string;

    /**
     * Whether this optimizer has anything to say about the given server.
     *
     * @param  array<string, string>  $probe
     */
    public function applies(ServerFacts $facts, array $probe): bool;

    /**
     * Turn measured facts into proposed configuration changes.
     *
     * Implementations compute only; nothing here writes to a server.
     *
     * @param  array<string, string>  $probe
     * @return array<int, TuningProposal>
     */
    public function propose(ServerFacts $facts, Budget $budget, array $probe): array;
}
