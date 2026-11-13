import { request } from "./client";
import { User } from "./types";

/**
 * Authentication and User Profile API Methods.
 */
export const authApi = {
  async register(
    name: string,
    email: string,
    password: string
  ): Promise<{ user: User; token: string | null; message?: string }> {
    const res = await request<{ user: User; token: string | null; message?: string }>("/register", {
      method: "POST",
      body: JSON.stringify({ name, email, password }),
    });
    if (res.token && typeof window !== "undefined") {
      localStorage.setItem("auth_token", res.token);
    }
    return res;
  },

  async login(
    email: string,
    password: string
  ): Promise<{ user: User; token: string }> {
    const res = await request<{ user: User; token: string }>("/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    });
    if (typeof window !== "undefined") {
      localStorage.setItem("auth_token", res.token);
    }
    return res;
  },

  async logout(): Promise<void> {
    try {
      await request<{ message: string }>("/logout", {
        method: "POST",
      });
    } catch {
      // Ignore network errors or unauthenticated state on logout
    } finally {
      if (typeof window !== "undefined") {
        localStorage.removeItem("auth_token");
      }
    }
  },

  async getMe(): Promise<{ user: User; roles: string[] }> {
    return request<{ user: User; roles: string[] }>("/me");
  },

  async stopImpersonation(): Promise<{ message: string; admin_token?: string | null }> {
    return request<{ message: string; admin_token?: string | null }>("/impersonation/stop", {
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
