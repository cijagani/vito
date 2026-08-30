import { OptimizationProposal } from '@/types/optimization';
import { Badge } from '@/components/ui/badge';
import { ArrowRightIcon, CheckIcon, ChevronDownIcon } from 'lucide-react';
import { useState } from 'react';

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
        <ChevronDownIcon className={`text-muted-foreground size-4 shrink-0 transition-transform ${open ? '' : '-rotate-90'}`} />

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

          {proposal.clamped && <p className="text-xs italic">Adjusted to stay within the safe range for this machine.</p>}

          {proposal.kb_ref && <p className="font-mono text-xs">{proposal.kb_ref}</p>}
        </div>
      )}
    </div>
  );
}

export default function ProposalList({ proposals }: { proposals: OptimizationProposal[] }) {
  const changes = proposals.filter((proposal) => proposal.is_change);
  const satisfied = proposals.filter((proposal) => !proposal.is_change);

  return (
    <div className="flex flex-col gap-6">
      {changes.length > 0 && (
        <div className="rounded-lg border">
          <div className="bg-muted/40 border-b px-4 py-2">
            <h3 className="text-sm font-medium">
              {changes.length} setting{changes.length === 1 ? '' : 's'} to change
            </h3>
          </div>
          {changes.map((proposal) => (
            <ProposalRow key={proposal.id} proposal={proposal} />
          ))}
        </div>
      )}

      {/* Shown so a reader can tell "checked and correct" from "never examined". */}
      {satisfied.length > 0 && (
        <div className="rounded-lg border">
          <div className="bg-muted/40 border-b px-4 py-2">
            <h3 className="text-muted-foreground text-sm font-medium">
              {satisfied.length} already correct
            </h3>
          </div>
          {satisfied.map((proposal) => (
            <ProposalRow key={proposal.id} proposal={proposal} />
          ))}
        </div>
      )}
    </div>
  );
}
