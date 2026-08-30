<?php

namespace App\Http\Resources;

use App\Models\OptimizationPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OptimizationPlan */
class OptimizationPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->server_id,
            'status' => $this->status->getText(),
            'status_color' => $this->status->getColor(),
            'source' => $this->source,
            'budget' => $this->budget,
            'facts' => $this->facts,
            'ruleset_versions' => $this->ruleset_versions,
            'is_disruptive' => $this->isDisruptive(),
            'proposals' => OptimizationProposalResource::collection(
                $this->whenLoaded('proposals')
            ),
            'created_at' => $this->created_at,
            'applied_at' => $this->applied_at,
            'rolled_back_at' => $this->rolled_back_at,
        ];
    }
}
