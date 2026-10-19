import { createContext, useContext, useState, useEffect, ReactNode } from 'react';
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
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const queryClient = useQueryClient();

  // Applies the number format before storing the user, so the re-render this
  // triggers already formats every amount with the user's separators.
  const applyUser = (userData: User) => {
    setNumberFormat(userData.numberFormat);
    setUser(userData);
  };

  useEffect(() => {
    // Check if user is authenticated on mount
    const loadUser = async () => {
      if (authApi.isAuthenticated()) {
        try {
          const userData = await authApi.getCurrentUser();
          applyUser(userData);
        } catch (error) {
          console.error('Failed to load user:', error);
          await authApi.logout();
        }
      }
      setLoading(false);
    };

    loadUser();
  }, []);

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
