import { OptimizationPlan } from '@/types/optimization';
import { Server } from '@/types/server';
import { Button } from '@/components/ui/button';
import { useDialog } from '@/hooks/use-dialog';
import { RotateCcwIcon } from 'lucide-react';

export default function ApplyActions({ server, plan }: { server: Server; plan: OptimizationPlan }) {
  const dialog = useDialog();

  const changes = (plan.proposals ?? []).filter((proposal) => proposal.accepted);
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

  if (plan.status === 'applied') {
    return (
      <Button variant="outline" onClick={confirmRollback}>
        <RotateCcwIcon />
        Roll back
      </Button>
    );
  }

  if (plan.status !== 'draft' || changes.length === 0) {
    return null;
  }

  return (
    <Button onClick={confirmApply}>
      Apply {changes.length} change{changes.length === 1 ? '' : 's'}
    </Button>
  );
}
