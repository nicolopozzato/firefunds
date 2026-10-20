import { createContext, useCallback, useContext, useState, useEffect, ReactNode } from 'react';
import axios from 'axios';
import { useQueryClient } from '@tanstack/react-query';
import { authApi } from '../lib/auth-api';
import { setNumberFormat } from '../lib/utils';
import type { User, LoginRequest, RegisterRequest } from '../types/auth';

interface AuthContextType {
  user: User | null;
  loading: boolean;
  login: (credentials: LoginRequest) => Promise<void>;
  register: (data: RegisterRequest) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  isAuthenticated: boolean;
  /** Signed in, but the server couldn't be reached when the app opened: the session is kept, to retry. */
  unreachable: unknown;
  retry: () => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [unreachable, setUnreachable] = useState<unknown>(null);
  const queryClient = useQueryClient();

  // Applies the number format before storing the user, so the re-render this
  // triggers already formats every amount with the user's separators.
  const applyUser = (userData: User) => {
    setNumberFormat(userData.numberFormat);
    setUser(userData);
  };

  // Who is signed in, when the app opens. Only a server that rejects the
  // session signs out; no answer (offline, a release restarting the server)
  // keeps it and offers to retry, so the installed app doesn't throw the user out.
  const loadUser = useCallback(async () => {
    setLoading(true);
    setUnreachable(null);
    if (authApi.isAuthenticated()) {
      try {
        applyUser(await authApi.getCurrentUser());
      } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 401) {
          await authApi.logout();
        } else {
          setUnreachable(error);
        }
      }
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    void loadUser();
  }, [loadUser]);

  // The cache holds the previous user's data: whoever logs in next in this
  // tab must not see it.
  const login = async (credentials: LoginRequest) => {
    queryClient.clear();
    await authApi.login(credentials);
    const userData = await authApi.getCurrentUser();
    applyUser(userData);
  };

  const register = async (data: RegisterRequest) => {
    await authApi.register(data);
    // After registration, auto-login
    await login({ email: data.email, password: data.password });
  };

  const logout = async () => {
    await authApi.logout();
    queryClient.clear();
    setUser(null);
  };

  const refreshUser = async () => {
    const userData = await authApi.getCurrentUser();
    applyUser(userData);
  };

  const value = {
    user,
    loading,
    login,
    register,
    logout,
    refreshUser,
    isAuthenticated: !!user,
    unreachable,
    retry: () => void loadUser(),
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
