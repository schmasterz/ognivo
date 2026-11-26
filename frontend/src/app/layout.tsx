import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";
import "./globals.css";
import React from "react";
import { ThemeProvider } from "@/lib/theme/ThemeContext";
import { I18nProvider } from "@/lib/i18n/I18nContext";
import { ToastProvider } from "@/lib/toast/ToastContext";
import { AuthProvider } from "@/lib/auth/AuthContext";
import { GlobalFilterProvider } from "@/lib/filters/GlobalFilterContext";
import { ConfigProvider } from "@/lib/config/ConfigContext";
import type { OgnivoRuntimeConfig } from "@/lib/config";
import { cookies, headers } from "next/headers";
import { loaders, type Locale } from "@/lib/i18n/loaders";
import { resolveLocale } from "@/lib/i18n/resolve-locale";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const instant = false;

export const metadata: Metadata = {
  title: "Ognivo",
  description: "Incus & LXD control plane",
  icons: {
    icon: [
      { url: "/icon.svg", type: "image/svg+xml" },
      { url: "/favicon.ico", sizes: "any" },
    ],
  },
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const cookieStore = await cookies();
  const headersList = await headers();
  const rawLocale = cookieStore.get("locale")?.value;
  const acceptLanguage = headersList.get("accept-language");
  const locale: Locale = resolveLocale(rawLocale, acceptLanguage);
  const messages = await loaders[locale]();

  const runtimeConfig: OgnivoRuntimeConfig = {
    app_name: process.env.NEXT_PUBLIC_APP_NAME || "Ognivo",
    app_env: process.env.NODE_ENV || "production",
    api_base_url: process.env.NEXT_PUBLIC_API_URL || "/api/v1",
    ws_host: process.env.NEXT_PUBLIC_REVERB_HOST || undefined,
    ws_port: Number.parseInt(process.env.NEXT_PUBLIC_REVERB_PORT ?? "", 10) || undefined,
    ws_key: process.env.NEXT_PUBLIC_REVERB_APP_KEY || process.env.REVERB_APP_KEY || undefined,
    features: {
      registration: process.env.NEXT_PUBLIC_ENABLE_REGISTRATION === "true",
      impersonation: process.env.NEXT_PUBLIC_ENABLE_IMPERSONATION !== "false",
    },
  };

  return (
    <html lang={locale} suppressHydrationWarning>
      <head>
        {/* Synchronous execution before first paint prevents theme FOUC */}
        {/* eslint-disable-next-line @next/next/no-sync-scripts */}
        <script src="/theme-init.js" />
      </head>
      <body className={`${geistSans.variable} ${geistMono.variable} antialiased`}>
        <ConfigProvider initialConfig={runtimeConfig}>
          <ThemeProvider>
            <I18nProvider locale={locale} messages={messages}>
              <ToastProvider>
                <AuthProvider>
                  <GlobalFilterProvider>{children}</GlobalFilterProvider>
                </AuthProvider>
              </ToastProvider>
            </I18nProvider>
          </ThemeProvider>
        </ConfigProvider>
      </body>
    </html>
  );
}
