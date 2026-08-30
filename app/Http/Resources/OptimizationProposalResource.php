<?php

namespace App\Http\Resources;

use App\Models\OptimizationProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptimizationProposal */
class OptimizationProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component' => $this->component,
            'config_key' => $this->config_key,
            'current_value' => $this->current_value,
            'proposed_value' => $this->proposed_value,
            'severity' => $this->severity->getText(),
            'severity_color' => $this->severity->getColor(),
            'apply_method' => $this->apply_method->getText(),
            'apply_method_color' => $this->apply_method->getColor(),
            'is_disruptive' => $this->apply_method->isDisruptive(),
            'rationale' => $this->rationale,
            'kb_ref' => $this->kb_ref,
            'clamped' => $this->clamped,
            'accepted' => $this->accepted,
            'is_change' => $this->isChange(),
            'applied_at' => $this->applied_at,
        ];
    }
}
