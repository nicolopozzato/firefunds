import { useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { accountsApi } from '@/lib/accounts-api'
import type { AccountListFilters, AccountType, CreateAccountRequest, DeleteAccountOptions, MoveAccountTarget, UpdateAccountRequest } from '@/types/account'

/**
 * The account type of the current page, read from the URL: account routes are
 * spelled out per type (`/accounts/asset/:id`, …) rather than using a `:type`
 * param, so `useParams()` doesn't carry it.
 */
export function useAccountTypeFromPath(): AccountType | null {
  const location = useLocation()
  const match = location.pathname.match(/^\/accounts\/(asset|expense|revenue)\b/)
  return (match?.[1] as AccountType) ?? null
}

export function useAccounts(type: AccountType, page = 1, filters: AccountListFilters = {}) {
  return useQuery({
    queryKey: ['accounts', type, page, filters],
    queryFn: () => accountsApi.list(type, page, undefined, filters),
    placeholderData: (previousData) => previousData,
  })
}

/**
 * Whether the user has any account of this type at all, whatever its state
 * or kind: tells "nothing matches the filters" from "no account yet".
 */
export function useHasAnyAccount(type: AccountType, enabled: boolean) {
  return useQuery({
    queryKey: ['accounts', type, 'any'],
    queryFn: async () => (await accountsApi.list(type, 1, 1)).total > 0,
    enabled,
  })
}

/** For selection UI (comboboxes) that must offer every account, not just one page. */
export function useAllAccounts(type: AccountType) {
  return useQuery({
    queryKey: ['accounts', type, 'all'],
    queryFn: () => accountsApi.list(type, 1, -1),
  })
}

export function useAccount(id: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['accounts', 'detail', id],
    queryFn: () => accountsApi.get(id),
    enabled: !!id,
  })
}

export function useAccountBalanceHistory(id: string, from?: string, to?: string) {
  return useQuery({
    queryKey: ['accounts', 'balance-history', id, from, to],
    queryFn: () => accountsApi.balanceHistory(id, from, to),
    enabled: !!id,
  })
}

export function useAccountValueHistory(id: string, from?: string, to?: string) {
  return useQuery({
    queryKey: ['accounts', 'value-history', id, from, to],
    queryFn: () => accountsApi.valueHistory(id, from, to),
    enabled: !!id,
  })
}

export function useAccountOpenPositions(id: string, from?: string, to?: string) {
  return useQuery({
    queryKey: ['accounts', 'positions', id, from, to],
    queryFn: () => accountsApi.positions(id, from, to),
    enabled: !!id,
  })
}

export function useAccountDistributions(id: string, from?: string, to?: string) {
  return useQuery({
    queryKey: ['accounts', 'distributions', id, from, to],
    queryFn: () => accountsApi.distributions(id, from, to),
    enabled: !!id,
  })
}

export function useCreateAccount(type: AccountType) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: CreateAccountRequest) => accountsApi.create(type, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts', type] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useUpdateAccount(type: AccountType) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateAccountRequest }) =>
      accountsApi.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts', type] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

/** The order also drives the dashboard (boxes, chart lines and their colours), so it is refreshed too. */
export function useMoveAccount(type: AccountType) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, target }: { id: string; target: MoveAccountTarget }) => accountsApi.move(id, target),
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts', type] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
    },
  })
}

export function useAccountDeletionPreview(id: string | null) {
  return useQuery({
    queryKey: ['accounts', 'deletion-preview', id],
    queryFn: () => accountsApi.deletionPreview(id as string),
    enabled: id !== null,
    staleTime: 0,
  })
}

/** Moving or deleting transactions touches other accounts' balances too, so everything is refreshed. */
export function useDeleteAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, options }: { id: string; options?: DeleteAccountOptions }) => accountsApi.remove(id, options),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['accounts'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.invalidateQueries({ queryKey: ['reports'] })
      queryClient.invalidateQueries({ queryKey: ['transactions'] })
      queryClient.invalidateQueries({ queryKey: ['investment-transactions'] })
    },
  })
}
