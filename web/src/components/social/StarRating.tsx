import clsx from 'clsx'
import { Star } from 'lucide-react'

/** Five stars: read-only when no onChange is given. */
export default function StarRating({ value, onChange, size = 20 }: { value: number | null; onChange?: (n: number) => void; size?: number }) {
  return (
    <span className="inline-flex items-center gap-0.5" role={onChange ? 'radiogroup' : 'img'} aria-label={`${value ?? 0}/5`}>
      {[1, 2, 3, 4, 5].map((n) => {
        const filled = (value ?? 0) >= n - 0.25
        const star = <Star style={{ width: size, height: size }} className={clsx(filled ? 'fill-gold-500 text-gold-500' : 'text-navy-200')} />
        return onChange
          ? <button key={n} type="button" role="radio" aria-checked={Math.round(value ?? 0) === n} aria-label={String(n)} onClick={() => onChange(n)} className="rounded p-0.5 transition hover:scale-110 focus-visible:outline-2 focus-visible:outline-gold-500">{star}</button>
          : <span key={n}>{star}</span>
      })}
    </span>
  )
}
