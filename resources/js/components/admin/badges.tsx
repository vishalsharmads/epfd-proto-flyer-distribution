import { Badge } from '@/components/ui/surface';

export function BoundaryBadge({ source }: { source: string }) {
    return source === 'official' ? <Badge tone="success">Official</Badge> : <Badge tone="warning">Derived (approximate)</Badge>;
}
