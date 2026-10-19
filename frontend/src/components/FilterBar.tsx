import type { ReactNode } from 'react'

interface FilterBarProps {
  /** Filters on the left (e.g. what kind of item). */
  children?: ReactNode
  /** Filters on the right (e.g. the status). */
  end?: ReactNode
}

/** The row of filters above a list's table. */
export default function FilterBar({ children, end }: FilterBarProps) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div className="flex flex-wrap items-center gap-4">{children}</div>
      <div className="flex flex-wrap items-center gap-4">{end}</div>
    </div>
  )
}
