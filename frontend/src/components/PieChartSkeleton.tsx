import { Skeleton } from '@/components/ui/skeleton'

interface PieChartSkeletonProps {
  legendCount?: number
}

export default function PieChartSkeleton({ legendCount = 4 }: PieChartSkeletonProps) {
  return (
    <div className="surface p-4 sm:p-5">
      <Skeleton className="mb-3 h-4 w-32" />
      <div className="flex flex-row items-center gap-4 sm:items-start">
        <Skeleton className="h-24 w-24 shrink-0 rounded-full sm:h-32 sm:w-32 2xl:h-44 2xl:w-44" />
        <ul className="min-w-0 flex-1 space-y-1.5">
          {Array.from({ length: legendCount }).map((_, i) => (
            <li key={i} className="flex items-center gap-2 px-1 py-0.5">
              <Skeleton className="h-2.5 w-2.5 shrink-0 rounded-full" />
              <Skeleton className="h-4 flex-1" />
              <Skeleton className="h-4 w-12 shrink-0" />
            </li>
          ))}
        </ul>
      </div>
    </div>
  )
}
