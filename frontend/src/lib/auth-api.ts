import { api, setTokens, clearTokens, getAccessToken } from './api';
import type {
  LoginRequest,
  LoginResponse,
  RegisterRequest,
  RegisterResponse,
  CheckRegistrationResponse,
  User,
  UpdateProfileRequest,
  ChangePasswordRequest,
  UpdatePreferencesRequest,
} from '../types/auth';

export const authApi = {
  async login(credentials: LoginRequest): Promise<LoginResponse> {
    const response = await api.post<LoginResponse>('/api/auth/login', credentials);
    const { token, refresh_token } = response.data;
    setTokens(token, refresh_token);
    return response.data;
  },

  async register(data: RegisterRequest): Promise<RegisterResponse> {
    const response = await api.post<RegisterResponse>('/api/auth/register', data);
    return response.data;
  },

  async getCurrentUser(): Promise<User> {
    const response = await api.get<User>('/api/auth/me');
    return response.data;
  },

  async checkRegistration(): Promise<CheckRegistrationResponse> {
    const response = await api.get<CheckRegistrationResponse>('/api/auth/check-registration');
    return response.data;
  },

  async updateProfile(data: UpdateProfileRequest): Promise<User> {
    const response = await api.put<User>('/api/auth/me', data);
    return response.data;
  },

  async changePassword(data: ChangePasswordRequest): Promise<void> {
    await api.put('/api/auth/me/password', data);
  },

  async updatePreferences(data: UpdatePreferencesRequest): Promise<User> {
    const response = await api.put<User>('/api/auth/me/preferences', data);
    return response.data;
  },

  logout(): void {
    clearTokens();
  },

  isAuthenticated(): boolean {
    return !!getAccessToken();
  },
};
