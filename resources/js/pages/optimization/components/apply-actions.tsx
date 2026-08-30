import { OptimizationPlan } from '@/types/optimization';
import { Server } from '@/types/server';
import { Button } from '@/components/ui/button';
import { useDialog } from '@/hooks/use-dialog';
import { RotateCcwIcon } from 'lucide-react';

export default function ApplyActions({ server, plan }: { server: Server; plan: OptimizationPlan }) {
  const dialog = useDialog();

  // Only work still outstanding: a group already applied is not offered again.
  const changes = (plan.proposals ?? []).filter((proposal) => proposal.accepted && !proposal.applied_at);
  const restarts = changes.filter((proposal) => proposal.is_disruptive);

  const confirmApply = () => {
    // A restart drops open connections and any in-flight query, so the
    // consequence is named rather than left for the operator to infer.
    dialog.confirm.open({
      title: restarts.length > 0 ? 'Apply and restart' : 'Apply optimization',
      description:
        restarts.length > 0
          ? `${changes.length} setting${changes.length === 1 ? '' : 's'} will be written and the database restarted. Open connections and any in-flight queries are dropped.`
          : `${changes.length} setting${changes.length === 1 ? '' : 's'} will be written and the service reloaded.`,
      confirmLabel: restarts.length > 0 ? 'Apply and restart' : 'Apply',
      variant: restarts.length > 0 ? 'destructive' : 'default',
      method: 'post',
      url: route('optimization.apply', { server: server.id, plan: plan.id }),
      data: { confirmed: true },
    });
  };

  const confirmRollback = () => {
    dialog.confirm.open({
      title: 'Roll back',
      description:
        'Every file this plan changed is restored to the contents found before it was applied, and the database is restarted.',
      confirmLabel: 'Roll back',
      variant: 'destructive',
      method: 'post',
      url: route('optimization.rollback', { server: server.id, plan: plan.id }),
    });
  };

  // Rollback is offered as soon as anything has been written, not only when the
  // whole plan is done -- a group applied on its own is still something to undo.
  // Both buttons can show at once: applying one group leaves the others open.
  const hasApplied = (plan.proposals ?? []).some((proposal) => proposal.applied_at);
  const canApply = plan.status === 'draft' && changes.length > 0;

  if (!hasApplied && !canApply) {
    return null;
  }

  return (
    <div className="flex items-center gap-2">
      {hasApplied && (
        <Button variant="outline" onClick={confirmRollback}>
          <RotateCcwIcon />
          Roll back
        </Button>
      )}
      {canApply && <Button onClick={confirmApply}>Apply all {changes.length}</Button>}
    </div>
  );
}
