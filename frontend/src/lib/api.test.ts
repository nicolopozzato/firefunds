import { describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'
import { isSessionRejected } from './api'

const httpError = (status: number) =>
  new AxiosError('failed', 'ERR', undefined, undefined, {
    status,
    statusText: '',
    headers: {},
    config: { headers: new AxiosHeaders() },
    data: {},
  })

describe('isSessionRejected', () => {
  it('signs out only when the server rejects the refresh token', () => {
    expect(isSessionRejected(httpError(401))).toBe(true)
    expect(isSessionRejected(httpError(400))).toBe(true)
    expect(isSessionRejected(new Error('No refresh token'))).toBe(true)
  })

  it('keeps the session when the server is unreachable or busy', () => {
    expect(isSessionRejected(new AxiosError('Network Error', 'ERR_NETWORK'))).toBe(false)
    expect(isSessionRejected(httpError(502))).toBe(false)
    expect(isSessionRejected(httpError(429))).toBe(false)
  })
})
