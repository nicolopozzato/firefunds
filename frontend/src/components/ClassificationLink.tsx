import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

/** A category's or a tag's name that opens its page (inside a clickable row, the row's own click is left alone). */
export default function ClassificationLink({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link to={to} onClick={(event) => event.stopPropagation()} className="underline-offset-4 hover:underline">
      {children}
    </Link>
  )
}
