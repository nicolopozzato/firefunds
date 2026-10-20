import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { createClassificationApi, type ClassificationResource } from '@/lib/classification-api'
import type { ClassificationRequest } from '@/types/classification'
import { dataChanged } from '@/lib/data-changed'

export function useClassificationList(resource: ClassificationResource, page = 1) {
  const api = createClassificationApi(resource)

  return useQuery({
    queryKey: [resource, page],
    queryFn: () => api.list(page),
    placeholderData: (previousData) => previousData,
  })
}

/** For selection UI (comboboxes) that must offer every item, not just one page. */
export function useAllClassification(resource: ClassificationResource) {
  const api = createClassificationApi(resource)

  return useQuery({
    queryKey: [resource, 'all'],
    queryFn: () => api.list(1, -1),
  })
}

export function useClassificationItem(resource: ClassificationResource, id: string) {
  const api = createClassificationApi(resource)

  return useQuery({
    queryKey: [resource, 'detail', id],
    queryFn: () => api.get(id),
    enabled: !!id,
  })
}

export function useCreateClassification(resource: ClassificationResource) {
  const api = createClassificationApi(resource)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (data: ClassificationRequest) => api.create(data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useUpdateClassification(resource: ClassificationResource) {
  const api = createClassificationApi(resource)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: ClassificationRequest }) => api.update(id, data),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}

export function useClassificationDeletionPreview(resource: ClassificationResource, id: string | null) {
  const api = createClassificationApi(resource)

  return useQuery({
    queryKey: [resource, 'deletion-preview', id],
    queryFn: () => api.deletionPreview(id as string),
    enabled: id !== null,
    staleTime: 0,
  })
}

/** The transactions using it lose the category or tag. */
export function useDeleteClassification(resource: ClassificationResource) {
  const api = createClassificationApi(resource)
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => api.remove(id),
    onSuccess: () => {
      void dataChanged(queryClient)
    },
  })
}
