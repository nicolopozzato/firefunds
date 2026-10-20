import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { dashboardApi } from '@/lib/dashboard-api'
import { dataChanged } from '@/lib/data-changed'

export function useDashboard(from: string, to: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['dashboard', from, to],
    queryFn: () => dashboardApi.get(from, to),
    placeholderData: (previousData) => previousData,
  })
}

export function useSetDashboardAccounts() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (visibleAccountIds: string[]) => dashboardApi.setAccounts(visibleAccountIds),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}
