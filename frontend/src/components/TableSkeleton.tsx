import { cn } from '@/lib/utils'
import { TableBody, TableCell, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'

interface ColumnSpec {
  /** className applied to the TableCell, e.g. to match padding/alignment of the real column. */
  cellClassName?: string
  /** className applied to the Skeleton placeholder itself, e.g. size/shape/alignment. */
  skeletonClassName?: string
}

interface TableSkeletonProps {
  columns: ColumnSpec[]
  rowCount?: number
}

export default function TableSkeleton({ columns, rowCount = 5 }: TableSkeletonProps) {
  return (
    <TableBody>
      {Array.from({ length: rowCount }).map((_, rowIndex) => (
        <TableRow key={rowIndex}>
          {columns.map((column, colIndex) => (
            <TableCell key={colIndex} className={column.cellClassName}>
              <Skeleton className={cn('h-4 w-24', column.skeletonClassName)} />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </TableBody>
  )
}
