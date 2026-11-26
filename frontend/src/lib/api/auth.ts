import { request } from "./client";
import type { User } from "./types";

export interface ImpersonationStatus {
  active: true;
  impersonator_id: number;
  impersonator_name: string;
  expires_at: string;
}

export interface MeResponse {
  user: User;
  roles: string[];
  impersonation: ImpersonationStatus | null;
  session_expires_at: string | null;
}

/**
 * Authentication and User Profile API Methods.
 */
export const authApi = {
  async register(
    name: string,
    email: string,
    password: string
  ): Promise<{ user: User; message?: string }> {
    return request<{ user: User; message?: string }>("/register", {
      method: "POST",
      body: JSON.stringify({ name, email, password }),
    });
  },

  async login(email: string, password: string): Promise<{ user: User }> {
    return request<{ user: User }>("/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
  },

  async logout(): Promise<void> {
    try {
      await request<{ message: string }>("/logout", {
        method: "POST",
      });
    } catch {
      // Ignore network errors or unauthenticated state on logout
    }
  },

  async getMe(): Promise<MeResponse> {
    return request<MeResponse>("/me");
  },

  async stopImpersonation(): Promise<{ user: User; message?: string }> {
    return request<{ user: User; message?: string }>("/impersonation/stop", {
      method: "POST",
    });
  },

  async forgotPassword(email: string): Promise<{ message: string }> {
    return request<{ message: string }>("/forgot-password", {
      method: "POST",
      body: JSON.stringify({ email }),
    });
  },

  async resetPassword(params: {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
  }): Promise<{ message: string }> {
    return request<{ message: string }>("/reset-password", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async verifyEmail(params: {
    id: number;
    hash: string;
    expires: number;
    signature: string;
  }): Promise<{ message: string; already_verified?: boolean }> {
    return request<{ message: string; already_verified?: boolean }>("/email/verify", {
      method: "POST",
      body: JSON.stringify(params),
    });
  },

  async resendVerificationEmail(email: string): Promise<{ message: string }> {
    return request<{ message: string }>("/email/resend-verification", {
      method: "POST",
      body: JSON.stringify({ email }),
    });
  },

  async updateProfile(params: {
    name: string;
    email: string;
    current_password?: string;
    password?: string;
    password_confirmation?: string;
  }): Promise<{ message: string; user: User }> {
    return request<{ message: string; user: User }>("/me", {
      method: "PUT",
      body: JSON.stringify(params),
    });
  },
};
