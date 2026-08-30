import { OptimizationPlan, OptimizationProposal } from '@/types/optimization';
import { Server } from '@/types/server';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useDialog } from '@/hooks/use-dialog';
import { ArrowRightIcon, CheckIcon, ChevronDownIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * Groups are named for what an operator recognises on the budget bar, rather than
 * for the internal component key.
 */
const GROUP_LABELS: Record<string, string> = {
  postgresql: 'Database',
  mysql: 'Database',
  redis: 'Redis',
  'php-fpm': 'PHP-FPM',
  nginx: 'nginx',
  kernel: 'Kernel',
};

function ProposalRow({ proposal }: { proposal: OptimizationProposal }) {
  const [open, setOpen] = useState(false);

  return (
    <div className="border-b last:border-b-0">
      <button
        type="button"
        onClick={() => setOpen(!open)}
        aria-expanded={open}
        className="hover:bg-muted/50 flex w-full items-center gap-3 px-4 py-3 text-left"
      >
        <ChevronDownIcon
          className={`text-muted-foreground size-4 shrink-0 transition-transform ${open ? '' : '-rotate-90'}`}
        />

        <span className="min-w-0 flex-1 font-mono text-sm">{proposal.config_key}</span>

        {proposal.is_change ? (
          <span className="flex items-center gap-2 font-mono text-sm">
            <span className="text-muted-foreground line-through">{proposal.current_value ?? 'unset'}</span>
            <ArrowRightIcon className="text-muted-foreground size-3" />
            <span className="font-medium">{proposal.proposed_value}</span>
          </span>
        ) : (
          <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
            <CheckIcon className="size-3.5" />
            {proposal.proposed_value}
          </span>
        )}

        {proposal.is_change && <Badge variant={proposal.severity_color}>{proposal.severity}</Badge>}

        {proposal.is_disruptive && <Badge variant="outline">restart</Badge>}
      </button>

      {open && (
        <div className="text-muted-foreground space-y-2 px-4 pb-4 pl-11 text-sm">
          <p className="whitespace-pre-line">{proposal.rationale}</p>

          {proposal.clamped && (
            <p className="text-xs italic">Adjusted to stay within the safe range for this machine.</p>
          )}

          {proposal.kb_ref && <p className="font-mono text-xs">{proposal.kb_ref}</p>}
        </div>
      )}
    </div>
  );
}

function GroupCard({
  server,
  plan,
  label,
  components,
  proposals,
}: {
  server: Server;
  plan: OptimizationPlan;
  label: string;
  components: string[];
  proposals: OptimizationProposal[];
}) {
  const dialog = useDialog();

  const pending = proposals.filter((p) => p.is_change && !p.applied_at);
  const applied = proposals.filter((p) => p.applied_at);
  const correct = proposals.filter((p) => !p.is_change && !p.applied_at);
  const restarts = pending.filter((p) => p.is_disruptive);

  const confirmApply = () => {
    dialog.confirm.open({
      title: restarts.length > 0 ? `Apply ${label} and restart` : `Apply ${label}`,
      description:
        restarts.length > 0
          ? `${pending.length} ${label} setting${pending.length === 1 ? '' : 's'} will be written and the service restarted. Open connections and any in-flight work are dropped.`
          : `${pending.length} ${label} setting${pending.length === 1 ? '' : 's'} will be written and the service reloaded.`,
      confirmLabel: restarts.length > 0 ? 'Apply and restart' : 'Apply',
      variant: restarts.length > 0 ? 'destructive' : 'default',
      method: 'post',
      url: route('optimization.apply', { server: server.id, plan: plan.id }),
      data: { confirmed: true, components },
    });
  };

  return (
    <div className="rounded-lg border">
      <div className="bg-muted/40 flex items-center justify-between border-b px-4 py-2">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-medium">{label}</h3>
          {pending.length > 0 && (
            <span className="text-muted-foreground text-xs">
              {pending.length} to change
              {correct.length > 0 && `, ${correct.length} already correct`}
            </span>
          )}
          {pending.length === 0 && (
            <span className="text-muted-foreground text-xs">
              {applied.length > 0 ? `${applied.length} applied` : 'nothing to change'}
            </span>
          )}
        </div>

        {pending.length > 0 && (
          <Button size="sm" variant={restarts.length > 0 ? 'outline' : 'default'} onClick={confirmApply}>
            Apply {pending.length}
          </Button>
        )}
      </div>

      {[...pending, ...applied, ...correct].map((proposal) => (
        <ProposalRow key={proposal.id} proposal={proposal} />
      ))}
    </div>
  );
}

export default function ProposalList({
  server,
  plan,
  proposals,
}: {
  server: Server;
  plan: OptimizationPlan;
  proposals: OptimizationProposal[];
}) {
  // One card per budget line rather than per component key, so Database covers
  // whichever engine this server runs without the operator having to know which.
  const groups = new Map<string, { components: Set<string>; proposals: OptimizationProposal[] }>();

  for (const proposal of proposals) {
    const label = GROUP_LABELS[proposal.component] ?? proposal.component;
    const group = groups.get(label) ?? { components: new Set<string>(), proposals: [] };
    group.components.add(proposal.component);
    group.proposals.push(proposal);
    groups.set(label, group);
  }

  // Groups with work to do come first; within a group the engine has already
  // sorted by severity.
  const ordered = [...groups.entries()].sort(([, a], [, b]) => {
    const pendingIn = (g: typeof a) => g.proposals.filter((p) => p.is_change && !p.applied_at).length;

    return pendingIn(b) - pendingIn(a);
  });

  return (
    <div className="flex flex-col gap-4">
      {ordered.map(([label, group]) => (
        <GroupCard
          key={label}
          server={server}
          plan={plan}
          label={label}
          components={[...group.components]}
          proposals={group.proposals}
        />
      ))}
    </div>
  );
}
