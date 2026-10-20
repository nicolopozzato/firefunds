import axios, { AxiosError, InternalAxiosRequestConfig } from 'axios';

// Same origin by default: /api is proxied to the backend by Vite in dev and
// by FrankenPHP in the built app (docker/frankenphp/Caddyfile).
const API_BASE_URL = import.meta.env.VITE_API_URL ?? '';

// Create axios instance
export const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Token management
const TOKEN_KEY = 'access_token';
const REFRESH_TOKEN_KEY = 'refresh_token';

export const getAccessToken = (): string | null => localStorage.getItem(TOKEN_KEY);
export const getRefreshToken = (): string | null => localStorage.getItem(REFRESH_TOKEN_KEY);
export const setTokens = (accessToken: string, refreshToken: string): void => {
  localStorage.setItem(TOKEN_KEY, accessToken);
  localStorage.setItem(REFRESH_TOKEN_KEY, refreshToken);
};
export const clearTokens = (): void => {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(REFRESH_TOKEN_KEY);
};

// Request interceptor to add auth token
api.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = getAccessToken();
    if (token && config.headers) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

/**
 * Swaps the refresh token for new tokens and stores them. Refresh tokens are
 * single use and a spent one sent again revokes the session (backend
 * gesdinet_jwt_refresh_token.yaml), so tabs take turns through a Web Lock: a
 * tab that waited finds the pair another tab just stored and uses that.
 */
const refreshTokens = async (): Promise<string> => {
  const startedWith = getRefreshToken();

  const run = async (): Promise<string> => {
    const current = getRefreshToken();
    if (!current) {
      throw new Error('No refresh token');
    }
    if (current !== startedWith && getAccessToken()) {
      return getAccessToken() as string;
    }

    const response = await axios.post(`${API_BASE_URL}/api/auth/refresh`, { refresh_token: current });
    const { token, refresh_token } = response.data;
    setTokens(token, refresh_token);
    return token;
  };

  return navigator.locks ? navigator.locks.request('firefunds-token-refresh', run) : run();
};

/**
 * Whether a failed refresh means the session is over: the server rejected
 * the refresh token (expired, revoked, already used), or there is none. No
 * answer (offline, a restart during a release) or a 5xx/429 is not: the
 * session stays, and the next request tries again.
 */
export const isSessionRejected = (error: unknown): boolean => {
  if (axios.isAxiosError(error)) {
    const status = error.response?.status;
    return status === 400 || status === 401 || status === 403;
  }
  return error instanceof Error && error.message === 'No refresh token';
};

// Response interceptor to handle token refresh
let refreshing: Promise<string> | null = null;

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    const originalRequest = error.config as InternalAxiosRequestConfig & { _retry?: boolean };

    // A 401 from login, register, refresh or logout is an answer to show, not
    // an expired session: no refresh and no redirect.
    const isAuthCall = /\/api\/auth\/(login|register|refresh|logout)$/.test(originalRequest?.url ?? '');

    if (error.response?.status !== 401 || originalRequest._retry || isAuthCall) {
      return Promise.reject(error);
    }

    originalRequest._retry = true;

    if (!getRefreshToken()) {
      clearTokens();
      window.location.href = '/login';
      return Promise.reject(error);
    }

    try {
      // Requests failing together wait for the same refresh.
      refreshing ??= refreshTokens().finally(() => {
        refreshing = null;
      });
      const token = await refreshing;

      if (originalRequest.headers) {
        originalRequest.headers.Authorization = `Bearer ${token}`;
      }
      return api(originalRequest);
    } catch (refreshError) {
      if (isSessionRejected(refreshError)) {
        clearTokens();
        window.location.href = '/login';
      }
      // Otherwise the server couldn't be reached: the error is shown, the session kept.
      return Promise.reject(refreshError);
    }
  }
);
