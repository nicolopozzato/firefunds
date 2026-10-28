import { useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { accountsApi } from '@/lib/accounts-api'
import type { AccountListFilters, AccountType, CreateAccountRequest, DeleteAccountOptions, MoveAccountTarget, ReconcileAccountRequest, UpdateAccountRequest } from '@/types/account'
import { dataChanged } from '@/lib/data-changed'

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

/** `on`: the day the balances are at (yyyy-MM-dd), the last of the selected period; today when left out. */
export function useAccounts(type: AccountType, page = 1, filters: AccountListFilters = {}, on?: string) {
  return useQuery({
    queryKey: ['accounts', type, page, filters, on],
    queryFn: () => accountsApi.list(type, page, undefined, filters, on),
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

/** `on`: the day the balance is at (yyyy-MM-dd); today when left out (e.g. the edit form). */
export function useAccount(id: string, on?: string) {
  return useQuery({
    // The page shows its own error (LoadFailed).
    meta: { quietError: true },
    queryKey: ['accounts', 'detail', id, on],
    queryFn: () => accountsApi.get(id, on),
    enabled: !!id,
  })
}

export function useAccountAuditLog(id: string) {
  return useQuery({
    queryKey: ['accounts', 'audit-log', id],
    queryFn: () => accountsApi.auditLog(id),
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
      void dataChanged(queryClient)
    },
  })
}

export function useReconcileAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ReconcileAccountRequest }) => accountsApi.reconcile(id, data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useUpdateAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateAccountRequest }) =>
      accountsApi.update(id, data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

/** The order also drives the dashboard (boxes, chart lines and their colours). */
export function useMoveAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, target }: { id: string; target: MoveAccountTarget }) => accountsApi.move(id, target),
    onSettled: () => {
      void dataChanged(queryClient)
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


export function useDeleteAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, options }: { id: string; options?: DeleteAccountOptions }) => accountsApi.remove(id, options),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}
