import clsx from 'clsx'

/** Shimmering placeholder in the brand's ivory tones, shown while content loads. */
export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden className={clsx('skeleton rounded-xl', className)} />
}

/** Placeholder with the shape of a program card. */
export function ProgramCardSkeleton() {
  return (
    <div className="overflow-hidden rounded-[var(--radius-card,1.25rem)] border border-navy-100 bg-white" aria-hidden>
      <Skeleton className="h-44 rounded-none" />
      <div className="space-y-3 p-5">
        <Skeleton className="h-4 w-24" />
        <Skeleton className="h-5 w-4/5" />
        <Skeleton className="h-4 w-full" />
        <div className="flex gap-3 pt-2"><Skeleton className="h-4 w-20" /><Skeleton className="h-4 w-16" /><Skeleton className="h-4 w-14" /></div>
      </div>
    </div>
  )
}

export function ProgramGridSkeleton({ count = 6, className = 'grid gap-6 sm:grid-cols-2 lg:grid-cols-3' }: { count?: number; className?: string }) {
  return (
    <div className={className} role="status" aria-label="Loading">
      {Array.from({ length: count }, (_, i) => <ProgramCardSkeleton key={i} />)}
    </div>
  )
}

/** Placeholder for a detail page: hero band and text blocks. */
export function PageSkeleton() {
  return (
    <div className="mx-auto max-w-6xl space-y-6 px-4 pt-28" role="status" aria-label="Loading">
      <Skeleton className="h-64 w-full rounded-3xl" />
      <div className="grid gap-6 lg:grid-cols-3">
        <div className="space-y-3 lg:col-span-2"><Skeleton className="h-6 w-2/3" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-11/12" /><Skeleton className="h-4 w-4/5" /></div>
        <Skeleton className="h-56 w-full rounded-3xl" />
      </div>
    </div>
  )
}
