import type { PrintOrderT, ProductT } from '@/components/app/types';
import { cn, money } from '@/lib/utils';
import { BookOpen, Calendar, CreditCard, FileText, Flag, FolderOpen, Gift, Image, Magnet, Mail, Newspaper, Package, Palette, Printer, Shirt, StickyNote, Tag, Ticket } from 'lucide-react';

/** Icons an admin can give a print category or design service (keep in sync with PrintCategory::ICONS). */
export const CATEGORY_ICONS: Record<string, typeof Printer> = {
    'file-text': FileText,
    'book-open': BookOpen,
    magnet: Magnet,
    'credit-card': CreditCard,
    image: Image,
    flag: Flag,
    newspaper: Newspaper,
    printer: Printer,
    'sticky-note': StickyNote,
    tag: Tag,
    mail: Mail,
    package: Package,
    ticket: Ticket,
    calendar: Calendar,
    shirt: Shirt,
    gift: Gift,
    'folder-open': FolderOpen,
    palette: Palette,
};

export function CategoryIcon({ icon, className }: { icon?: string | null; className?: string }) {
    const Icon = (icon && CATEGORY_ICONS[icon]) || Printer;
    return <Icon className={className} aria-hidden />;
}

/** Product image when the admin uploaded one, otherwise a silhouette at the right aspect ratio. */
export function ProductArt({ product, icon, className }: { product: Pick<ProductT, 'width_mm' | 'height_mm'> & { image_url?: string | null; category_icon?: string | null }; icon?: string | null; className?: string }) {
    if (product.image_url) {
        return (
            <div className={cn('overflow-hidden bg-surface-2', className)}>
                <img src={product.image_url} alt="" loading="lazy" className="size-full object-cover" />
            </div>
        );
    }
    const ratio = product.width_mm / product.height_mm;
    return (
        <div className={cn('grid place-items-center bg-surface-2', className)} aria-hidden>
            <div className="grid place-items-center rounded-[6px] border border-border bg-surface shadow-sm" style={{ height: ratio > 1 ? `${Math.max(30, 64 / ratio)}%` : '64%', aspectRatio: String(ratio) }}>
                <CategoryIcon icon={icon ?? product.category_icon} className="size-6 text-subtle" />
            </div>
        </div>
    );
}

export function fromPrice(p: ProductT) {
    return p.from_price_cents < 100 ? `from ${p.from_price_cents}c/pc` : `from ${money(p.from_price_cents)}/pc`;
}

export function printStepUrl(o: Pick<PrintOrderT, 'id' | 'step' | 'product'>) {
    return `/app/print/${o.id}/${!o.product ? 'product' : o.step >= 4 ? 'confirm' : 'specs'}`;
}
