import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { apiTokensApi, draftsApi } from '@/lib/drafts-api'
import { dataChanged } from '@/lib/data-changed'
import type { ApiTokenScope } from '@/types/draft'
import type { CreateTransactionRequest } from '@/types/transaction'

/** Drafts waiting for the user; looked at again every minute, like the bell. */
export function useDrafts() {
  return useQuery({ queryKey: ['drafts', 'list'], queryFn: () => draftsApi.list(), refetchInterval: 60_000 })
}

export function useDraft(id: string) {
  return useQuery({ meta: { quietError: true }, queryKey: ['drafts', 'detail', id], queryFn: () => draftsApi.get(id), enabled: !!id })
}

/** Accepting, linking or discarding changes transactions, balances and the bell: everything refetches. */
function useDraftMutation<T, R>(mutationFn: (variables: T) => Promise<R>) {
  const queryClient = useQueryClient()
  return useMutation({ mutationFn, onSuccess: () => void dataChanged(queryClient) })
}

export function useAcceptDraft() {
  return useDraftMutation(({ id, parts }: { id: string; parts: CreateTransactionRequest[] }) => draftsApi.accept(id, parts))
}

export function useLinkDraft() {
  return useDraftMutation(({ id, transactionId }: { id: string; transactionId: string }) => draftsApi.link(id, transactionId))
}

export function useDiscardDraft() {
  return useDraftMutation((id: string) => draftsApi.discard(id))
}

export function useApiTokens() {
  return useQuery({ queryKey: ['api-tokens'], queryFn: () => apiTokensApi.list() })
}

export function useCreateApiToken() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ name, scope }: { name: string; scope: ApiTokenScope }) => apiTokensApi.create(name, scope),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-tokens'] }),
  })
}

export function useRevokeApiToken() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => apiTokensApi.revoke(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['api-tokens'] }),
  })
}
