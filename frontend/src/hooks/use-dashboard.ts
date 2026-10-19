import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { dashboardApi } from '@/lib/dashboard-api'

export function useDashboard(from: string, to: string) {
  return useQuery({
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
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
    },
  })
}
