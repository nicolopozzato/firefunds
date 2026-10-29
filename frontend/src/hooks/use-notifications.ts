import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { notificationsApi } from '@/lib/notifications-api'
import { api } from '@/lib/api'
import type { PushDevice } from '@/lib/push'

/** The bell's list: looked at again every minute and when the app comes back. */
export function useNotifications() {
  return useQuery({
    queryKey: ['notifications'],
    queryFn: () => notificationsApi.list(),
    refetchInterval: 60_000,
    // A failed refresh of the bell is not worth a toast.
    meta: { quietError: true },
  })
}

function useNotificationMutation<T>(mutationFn: (variables: T) => Promise<void>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn,
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['notifications'] }),
  })
}

export function useReadNotification() {
  return useNotificationMutation((id: string) => notificationsApi.read(id))
}

export function useReadAllNotifications() {
  return useNotificationMutation(() => notificationsApi.readAll())
}

export function useDismissNotification() {
  return useNotificationMutation((id: string) => notificationsApi.dismiss(id))
}

/** The user's devices that receive push notifications. */
export function usePushDevices(enabled = true) {
  return useQuery({
    queryKey: ['push-devices'],
    enabled,
    queryFn: async () => (await api.get<PushDevice[]>('/api/push/subscriptions')).data,
  })
}

export function useRemovePushDevice() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => api.delete(`/api/push/subscriptions/${id}`),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['push-devices'] }),
  })
}

export function useTestPushDevice() {
  return useMutation({ mutationFn: (id: string) => api.post(`/api/push/subscriptions/${id}/test`) })
}
