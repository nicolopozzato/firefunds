import { keepPreviousData, useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { dataChanged } from '@/lib/data-changed'
import { adminApi } from '@/lib/admin-api'
import { useAuth } from '@/contexts/AuthContext'
import type {
  AdminUserFields,
  BackupSettings,
  CreateUserRequest,
  ServerSettings,
  SetPasswordRequest,
  TaskKey,
} from '@/types/admin'

export function useServerSettings() {
  return useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: () => adminApi.getSettings(),
  })
}

export function useUpdateServerSettings() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: ServerSettings) => adminApi.updateSettings(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'settings'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'update'] })
    },
  })
}

/**
 * The newer FireFunds release, for administrators only: null when there is
 * none (or the check is off). The server looks twice a day, so an hour old is fresh.
 */
export function useAvailableUpdate() {
  const { user } = useAuth()
  const { data } = useQuery({
    queryKey: ['admin', 'update'],
    queryFn: () => adminApi.getUpdate(),
    enabled: !!user?.isAdmin,
    staleTime: 60 * 60 * 1000,
  })

  return user?.isAdmin && data?.updateAvailable && data.latest ? { latest: data.latest, current: data.currentVersion } : null
}

export function useBackups() {
  return useQuery({
    queryKey: ['admin', 'backups'],
    queryFn: () => adminApi.getBackups(),
  })
}

export function useUpdateBackupSettings() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: BackupSettings) => adminApi.updateBackupSettings(data),
    onSuccess: (overview) => queryClient.setQueryData(['admin', 'backups'], overview),
  })
}

export function useCreateBackup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => adminApi.createBackup(),
    onSuccess: (overview) => queryClient.setQueryData(['admin', 'backups'], overview),
  })
}

export function useTasks() {
  return useQuery({
    queryKey: ['admin', 'tasks'],
    queryFn: () => adminApi.getTasks(),
  })
}

export function useTaskRuns(key: TaskKey, page: number, pageSize: number, enabled: boolean) {
  return useQuery({
    queryKey: ['admin', 'task-runs', key, page, pageSize],
    queryFn: () => adminApi.getTaskRuns(key, page, pageSize),
    enabled,
    placeholderData: keepPreviousData,
  })
}

/** A task run now: prices and rates change figures everywhere, so everything else is refetched too. */
export function useRunTask() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (key: TaskKey) => adminApi.runTask(key),
    onSuccess: (overview) => {
      queryClient.setQueryData(['admin', 'tasks'], overview)
      void queryClient.invalidateQueries({ queryKey: ['admin', 'task-runs'] })
      void dataChanged(queryClient)
    },
  })
}

export function useAdminUsers() {
  return useQuery({
    queryKey: ['admin', 'users'],
    queryFn: () => adminApi.listUsers(),
  })
}

/** Create, edit, set the password of, disable and enable a user; each refetches the list (its order follows who is active). */
export function useAdminUserMutations() {
  const queryClient = useQueryClient()
  const onSuccess = () => queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })

  return {
    create: useMutation({ mutationFn: (data: CreateUserRequest) => adminApi.createUser(data), onSuccess }),
    update: useMutation({
      mutationFn: ({ id, data }: { id: string; data: AdminUserFields }) => adminApi.updateUser(id, data),
      onSuccess,
    }),
    setPassword: useMutation({
      mutationFn: ({ id, data }: { id: string; data: SetPasswordRequest }) => adminApi.setUserPassword(id, data),
      onSuccess,
    }),
    setActive: useMutation({
      mutationFn: ({ id, active }: { id: string; active: boolean }) => adminApi.setUserActive(id, active),
      onSuccess,
    }),
  }
}
