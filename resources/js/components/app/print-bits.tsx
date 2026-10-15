import type { PrintOrderT, ProductT } from '@/components/app/types';
import { cn, money } from '@/lib/utils';
import { BookOpen, CreditCard, FileText, Flag, Image, Magnet, Newspaper, Printer } from 'lucide-react';

const icons: Record<string, typeof Printer> = { flyer: FileText, brochure: BookOpen, magnet: Magnet, business_card: CreditCard, poster: Image, banner: Flag, catalogue: Newspaper };

/** Product silhouette at the right aspect ratio (no stock photos). */
export function ProductArt({ product, className }: { product: Pick<ProductT, 'category' | 'width_mm' | 'height_mm'>; className?: string }) {
    const Icon = icons[product.category] ?? Printer;
    const ratio = product.width_mm / product.height_mm;
    return (
        <div className={cn('grid place-items-center bg-surface-2', className)} aria-hidden>
            <div className="grid place-items-center rounded-[6px] border border-border bg-surface shadow-sm" style={{ height: ratio > 1 ? `${Math.max(30, 64 / ratio)}%` : '64%', aspectRatio: String(ratio) }}>
                <Icon className="size-6 text-subtle" />
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
