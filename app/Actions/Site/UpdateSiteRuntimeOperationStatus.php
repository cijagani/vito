<?php

namespace App\Actions\Site;

use App\Enums\SiteRuntimeOperationStatus;
use App\Models\SiteRuntimeOperation;
use Illuminate\Support\Facades\DB;
use LogicException;

class UpdateSiteRuntimeOperationStatus
{
    public function update(
        SiteRuntimeOperation $operation,
        SiteRuntimeOperationStatus $status,
        ?string $error = null,
    ): SiteRuntimeOperation {
        if (! $operation->exists) {
            throw new LogicException('A runtime operation must be persisted before its status changes.');
        }

        if ($status === SiteRuntimeOperationStatus::FAILED && ($error === null || trim($error) === '')) {
            throw new LogicException('A failed runtime operation requires a redacted error message.');
        }

        DB::transaction(function () use ($operation, $status, $error): void {
            $lockedOperation = SiteRuntimeOperation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->canTransition($lockedOperation->status, $status)) {
                throw new LogicException(
                    "Runtime operation cannot transition from {$lockedOperation->status->value} to {$status->value}."
                );
            }

            $lockedOperation->status = $status;
            $lockedOperation->error = $error;

            if ($status === SiteRuntimeOperationStatus::RUNNING) {
                $lockedOperation->started_at = now();
            }

            if (in_array($status, [
                SiteRuntimeOperationStatus::SUCCEEDED,
                SiteRuntimeOperationStatus::FAILED,
                SiteRuntimeOperationStatus::ROLLED_BACK,
            ], true)) {
                $lockedOperation->finished_at = now();
            }

            $lockedOperation->save();
        });

        $operation->refresh();

        return $operation;
    }

    private function canTransition(
        SiteRuntimeOperationStatus $from,
        SiteRuntimeOperationStatus $to,
    ): bool {
        return match ($from) {
            SiteRuntimeOperationStatus::PENDING => in_array($to, [
                SiteRuntimeOperationStatus::RUNNING,
                SiteRuntimeOperationStatus::FAILED,
            ], true),
            SiteRuntimeOperationStatus::RUNNING => in_array($to, [
                SiteRuntimeOperationStatus::SUCCEEDED,
                SiteRuntimeOperationStatus::FAILED,
                SiteRuntimeOperationStatus::ROLLED_BACK,
            ], true),
            SiteRuntimeOperationStatus::SUCCEEDED,
            SiteRuntimeOperationStatus::FAILED,
            SiteRuntimeOperationStatus::ROLLED_BACK => false,
        };
    }
}
